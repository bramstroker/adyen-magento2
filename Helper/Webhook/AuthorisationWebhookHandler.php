<?php
/**
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Helper\Webhook;


use Adyen\Payment\Helper\AdyenOrderPayment;
use Adyen\Payment\Helper\CaseManagement;
use Adyen\Payment\Helper\ChargedCurrency;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Invoice;
use Adyen\Payment\Helper\Order as OrderHelper;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Notification;
use Adyen\Webhook\PaymentStates;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Model\Order;

class AuthorisationWebhookHandler implements WebhookHandlerInterface
{
    /** @var AdyenOrderPayment */
    private $adyenOrderPaymentHelper;

    /** @var OrderHelper */
    private $orderHelper;

    /** @var CaseManagement */
    private $caseManagementHelper;

    /** @var SerializerInterface */
    private $serializer;

    /** @var AdyenLogger */
    private $adyenLogger;

    /** @var ChargedCurrency */
    private $chargedCurrency;

    /** @var Config */
    private $configHelper;

    /** @var Invoice */
    private $invoiceHelper;

    /** @var PaymentMethods */
    private $paymentMethodsHelper;

    public function __construct(
        AdyenOrderPayment $adyenOrderPayment,
        OrderHelper $orderHelper,
        CaseManagement $caseManagementHelper,
        SerializerInterface $serializer,
        AdyenLogger $adyenLogger,
        ChargedCurrency $chargedCurrency,
        Config $configHelper,
        Invoice $invoiceHelper,
        PaymentMethods $paymentMethodsHelper
    )
    {
        $this->adyenOrderPaymentHelper = $adyenOrderPayment;
        $this->orderHelper = $orderHelper;
        $this->caseManagementHelper = $caseManagementHelper;
        $this->serializer = $serializer;
        $this->adyenLogger = $adyenLogger;
        $this->chargedCurrency = $chargedCurrency;
        $this->configHelper = $configHelper;
        $this->invoiceHelper = $invoiceHelper;
        $this->paymentMethodsHelper = $paymentMethodsHelper;
    }

    /**
     * @param Order $order
     * @param Notification $notification
     * @param string $transitionState
     * @return Order
     * @throws LocalizedException
     */
    public function handleWebhook(Order $order, Notification $notification, string $transitionState): Order
    {
        if ($transitionState === PaymentStates::STATE_PAID) {
            $order = $this->handleSuccessfulAuthorisation($order, $notification);
        } elseif ($transitionState === PaymentStates::STATE_FAILED) {
            $order = $this->handleFailedAuthorisation($order, $notification);
        }

        return $order;
    }

    /**
     * @param Order $order
     * @param Notification $notification
     * @return Order
     * @throws LocalizedException
     */
    private function handleSuccessfulAuthorisation(Order $order, Notification $notification): Order
    {
        $isAutoCapture = $this->paymentMethodsHelper->isAutoCapture($order, $notification->getPaymentMethod());

        // Set adyen_notification_payment_captured to true so that we ignore a possible OFFER_CLOSED
        if ($notification->isSuccessful() && $isAutoCapture) {
            $order->setData('adyen_notification_payment_captured', 1);
        }

        $this->adyenOrderPaymentHelper->createAdyenOrderPayment($order, $notification, $isAutoCapture);
        $isFullAmountAuthorized = $this->adyenOrderPaymentHelper->isFullAmountAuthorized($order);

        if ($isFullAmountAuthorized) {
            $order = $this->orderHelper->setPrePaymentAuthorized($order);
            $this->orderHelper->updatePaymentDetails($order, $notification);

            $additionalData = !empty($notification->getAdditionalData()) ? $this->serializer->unserialize($notification->getAdditionalData()) : "";
            $requireFraudManualReview = $this->caseManagementHelper->requiresManualReview($additionalData);

            if ($isAutoCapture) {
                $order = $this->handleAutoCapture($order, $notification, $requireFraudManualReview);
            } else {
                $order = $this->handleManualCapture($order, $notification, $requireFraudManualReview);
            }

            // For Boleto confirmation mail is sent on order creation
            // Send order confirmation mail after invoice creation so merchant can add invoicePDF to this mail
            if ($notification->getPaymentMethod() != "adyen_boleto" && !$order->getEmailSent()) {
                $this->orderHelper->sendOrderMail($order);
            }
        } else {
            $this->orderHelper->addWebhookStatusHistoryComment($order, $notification);
        }

        // Set authorized amount in sales_order_payment
        $orderAmountCurrency = $this->chargedCurrency->getOrderAmountCurrency($order, false);
        $orderAmount = $orderAmountCurrency->getAmount();
        $order->getPayment()->setAmountAuthorized($orderAmount);

        if ($notification->getPaymentMethod() == "c_cash" &&
            $this->configHelper->getConfigData('create_shipment', 'adyen_cash', $order->getStoreId())
        ) {
            $this->orderHelper->createShipment($order);
        }

        return $order;
    }

    /**
     * @param Order $order
     * @param Notification $notification
     * @return Order
     * @throws LocalizedException
     */
    private function handleFailedAuthorisation(Order $order, Notification $notification): Order
    {
        $previousAdyenEventCode = $order->getData('adyen_notification_event_code');
        $ignoreHasInvoice = true;

        // if payment is API, check if API result pspreference is the same as reference
        if ($notification->getEventCode() == Notification::AUTHORISATION) {
            if ('api' === $order->getPayment()->getPaymentMethodType()) {
                // don't cancel the order because order was successful through api
                $this->adyenLogger->addAdyenNotificationCronjob(
                    'order is not cancelled because api result was successful'
                );

                return $order;
            }
            $ignoreHasInvoice = false;
        }

        /*
         * Don't cancel the order if part of the payment has been captured.
         * Partial payments can fail, if the second payment has failed then the first payment is
         * refund/cancelled as well. So if it is a partial payment that failed cancel the order as well
         * TODO: Refactor this by using the adyenOrderPayment Table
         */
        $paymentPreviouslyCaptured = $order->getData('adyen_notification_payment_captured');

        if ($previousAdyenEventCode == "AUTHORISATION : TRUE" || !empty($paymentPreviouslyCaptured)) {
            $this->adyenLogger->addAdyenNotificationCronjob(
                'Order is not cancelled because previous notification
                                    was an authorisation that succeeded and payment was captured'
            );

            return $order;
        }

        // Order is already Cancelled
        if ($order->isCanceled() || $order->getState() === Order::STATE_HOLDED) {
            $this->adyenLogger->addAdyenNotificationCronjob(
                "Order is already cancelled or holded, do nothing"
            );

            return $order;
        }

        // Move the order from PAYMENT_REVIEW to NEW, so that can be cancelled
        if (!$order->canCancel() && $this->configHelper->getNotificationsCanCancel($order->getStoreId())) {
            $order->setState(Order::STATE_NEW);
        }

        return $this->orderHelper->holdCancelOrder($order, $ignoreHasInvoice);
    }

    /**
     * @param Order $order
     * @param Notification $notification
     * @param bool $requireFraudManualReview
     * @return Order
     * @throws LocalizedException
     */
    private function handleAutoCapture(Order $order, Notification $notification, bool $requireFraudManualReview): Order
    {
        $this->invoiceHelper->createInvoice($order, $notification, true);
        if ($requireFraudManualReview) {
             $order = $this->caseManagementHelper->markCaseAsPendingReview($order, $notification->getPspreference(), true);
        } else {
            $order = $this->orderHelper->finalizeOrder($order, $notification);
        }

        return $order;
    }

    /**
     * @param Order $order
     * @param Notification $notification
     * @param bool $requireFraudManualReview
     * @return Order
     */
    private function handleManualCapture(Order $order, Notification $notification, bool $requireFraudManualReview): Order
    {
        if ($requireFraudManualReview) {
            $order = $this->caseManagementHelper->markCaseAsPendingReview($order, $notification->getPspreference(), false);
        } else {
            $order = $this->orderHelper->addWebhookStatusHistoryComment($order, $notification);
            $order->addStatusHistoryComment(__('Capture Mode set to Manual'), $order->getStatus());
            $this->adyenLogger->addAdyenNotificationCronjob('Capture mode is set to Manual');
        }

        return $order;
    }
}

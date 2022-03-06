<?php
namespace verbb\postie\services;

use verbb\postie\Postie;
use verbb\postie\events\ModifyShippingMethodsEvent;
use verbb\postie\models\ShippingMethod;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\events\RegisterAvailableShippingMethodsEvent;

use yii\base\Component;
use yii\base\Event;

class Service extends Component
{
    // Properties
    // =========================================================================

    const EVENT_BEFORE_REGISTER_SHIPPING_METHODS = 'beforeRegisterShippingMethods';


    // Public Methods
    // =========================================================================

    public function onAfterSaveOrder(Event $event): void
    {
        if (!is_a($event->element, Order::class)) {
            return;
        }

        $settings = Postie::$plugin->getSettings();
        $request = Craft::$app->getRequest();

        // Only care about this being enabled
        if (!$settings->manualFetchRates) {
            return;
        }

        if ($request->getIsConsoleRequest()) {
            return;
        }

        // Check it matches the config variable
        if ($request->getParam('fetchRatesPostValue') == $settings->fetchRatesPostValue) {
            Craft::$app->getSession()->set('postieManualFetchRates', true);
        }
    }

    public function onBeforeSavePluginSettings($event): void
    {
        $settings = $event->plugin->getSettings();

        // Remove shipping methods of all disabled providers to keep PC under control.
        $providers = $settings->providers ?? [];

        foreach ($providers as &$provider) {
            if ($provider['enabled']) {
                continue;
            }

            unset($provider['services']);
        }

        unset($provider);

        // Patch in any shipping categories defined for each enabled providers' services
        foreach ($providers as $providerHandle => &$provider) {
            if ($provider['enabled']) {
                // Fetch providers from plugin settings
                $pluginInfo = Craft::$app->plugins->getStoredPluginInfo('postie');

                // Get the current services, and merge in the new changes, retaining existing (other) data
                $currentServices = $pluginInfo['settings']['providers'][$providerHandle]['services'] ?? [];
                $services = $provider['services'] ?? [];
                
                foreach ($services as $serviceHandle => &$service) {
                    $currentService = $currentServices[$serviceHandle] ?? [];

                    if ($currentService) {
                        $service = array_merge($currentService, $service);
                    }                    
                }

                unset($service);

                if ($services) {
                    $provider['services'] = $services;
                }
            }
        }

        unset($provider);

        $settings->providers = $providers;

        $event->plugin->setSettings($settings->toArray());
    }

    /**
     * @return ShippingMethod[]
     */
    public function getShippingMethodsForOrder(Order $order): array
    {
        // Fetch all providers (enabled or otherwise)
        $providers = Postie::getInstance()->getProviders()->getAllProviders();

        $shippingMethods = [];

        foreach ($providers as $provider) {
            if (!$provider->enabled) {
                continue;
            }

            // If this is a completed order, DO NOT fetch live rates.
            // Instead, return a shipping method model pre-populated with the rate already set on the order
            // This is so we can still have registered shipping methods for `order.shippingMethod.name`
            if ($order->isCompleted) {
                // The only reason we want to return live rates for a completed order is if we are recalculating
                if ($order->getRecalculationMode() != Order::RECALCULATION_MODE_ALL) {
                    foreach ($provider->getShippingMethods($order) as $shippingMethod) {
                        if ($shippingMethod->handle === $order->shippingMethodHandle) {
                            $shippingMethod->rate = $order->storedTotalShippingCost;
                            $shippingMethod->rateOptions = [];

                            $shippingMethods[] = $shippingMethod;
                        }
                    }

                    continue;
                }
            }

            // Fetch all available shipping rates
            $rates = $provider->getShippingRates($order);

            // Only return shipping rates for methods we've enabled
            foreach ($provider->getShippingMethods($order) as $shippingMethod) {
                $rate = $rates[$shippingMethod->handle] ?? [];

                if ($rate) {
                    $shippingMethod->rate = $rate['amount'] ?? 0;
                    $shippingMethod->rateOptions = $rate['options'] ?? [];

                    // Override the rate if this order matches a free-shipping order
                    if ($this->applyFreeShipping($order)) {
                        $shippingMethod->rate = 0;
                    }

                    $shippingMethods[] = $shippingMethod;
                }
            }
        }

        return $shippingMethods;
    }

    public function registerShippingMethods(RegisterAvailableShippingMethodsEvent $event): void
    {
        if (!$event->order) {
            return;
        }

        $shippingMethods = $this->getShippingMethodsForOrder($event->order);

        $modifyShippingMethodsEvent = new ModifyShippingMethodsEvent([
            'order' => $event->order,
            'shippingMethods' => $shippingMethods,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_REGISTER_SHIPPING_METHODS)) {
            $this->trigger(self::EVENT_BEFORE_REGISTER_SHIPPING_METHODS, $modifyShippingMethodsEvent);
        }

        foreach ($modifyShippingMethodsEvent->shippingMethods as $shippingMethod) {
            $event->shippingMethods[] = $shippingMethod;
        }
    }


    // Private Methods
    // =========================================================================

    private function applyFreeShipping($order): bool
    {
        $settings = Postie::$plugin->getSettings();

        if (!$settings->applyFreeShipping) {
            return false;
        }

        $freeShippingItems = [];

        foreach ($order->lineItems as $lineItem) {
            $freeShippingItems[] = $lineItem->purchasable->hasFreeShipping();
        }

        // Are _all_ items in the array the same? Does every item have free shipping?
        return (bool)array_product($freeShippingItems);
    }
}

<?php
namespace verbb\postie\providers;

use verbb\postie\Postie;
use verbb\postie\base\SinglePackageProvider;
use verbb\postie\base\Provider;
use verbb\postie\events\ModifyRatesEvent;

use Craft;
use craft\helpers\Json;

use craft\commerce\Plugin as Commerce;

class Sendle extends SinglePackageProvider
{
    // Properties
    // =========================================================================

    public $weightUnit = 'kg';
    public $dimensionUnit = 'cm';

    private $maxDomesticWeight = 25000; // 25kg
    private $maxInternationalWeight = 31751.5; // 70lbs

    
    // Public Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('postie', 'Sendle');
    }

    public function supportsDynamicServices(): bool
    {
        return true;
    }

    public function getMaxPackageWeight($order)
    {
        if ($this->getIsInternational($order)) {
            return $this->maxInternationalWeight;
        }

        return $this->maxDomesticWeight;
    }


    // Protected Methods
    // =========================================================================

    protected function fetchShippingRate($order, $packedBox)
    {
        //
        // TESTING
        //
        // $country = Commerce::getInstance()->countries->getCountryByIso('AU');
        // $state = Commerce::getInstance()->states->getStateByAbbreviation($country->id, 'VIC');

        // $storeLocation = new craft\commerce\models\Address();
        // $storeLocation->address1 = '552 Victoria Street';
        // $storeLocation->city = 'North Melbourne';
        // $storeLocation->zipCode = '3051';
        // $storeLocation->stateId = $state->id;
        // $storeLocation->countryId = $country->id;

        // $country = Commerce::getInstance()->countries->getCountryByIso('AU');
        // $state = Commerce::getInstance()->states->getStateByAbbreviation($country->id, 'TAS');

        // $order->shippingAddress->address1 = '10-14 Cameron Street';
        // $order->shippingAddress->city = 'Launceston';
        // $order->shippingAddress->zipCode = '7250';
        // $order->shippingAddress->stateId = $state->id;
        // $order->shippingAddress->countryId = $country->id;
        //
        // 
        //

        try {
            $response = [];

            $payload = [
                'pickup_suburb' => $storeLocation->city,
                'pickup_postcode' => $storeLocation->zipCode,
                'pickup_country' => $storeLocation->country->iso,
                'delivery_suburb' => $order->shippingAddress->city,
                'delivery_postcode' => $order->shippingAddress->zipCode,
                'delivery_country' => $order->shippingAddress->country->iso,
                'weight_value' => $packedBox['weight'],
                'weight_units' => $this->weightUnit,
            ];

            $this->beforeSendPayload($this, $payload, $order);

            $response = $this->_request('GET', 'quote', [
                'query' => $payload,
            ]);

            if ($response) {
                foreach ($response as $service) {
                    // Update our overall rates, set the cache, etc
                    $this->setRate($packedBox, [
                        'key' => $service['plan_name'],
                        'value' => [
                            'amount' => (float)$service['quote']['gross']['amount'] ?? '',
                            'options' => $service,
                        ],
                    ]);
                }
            } else {
                Provider::log($this, Craft::t('postie', 'No services found: `{json}`.', [
                    'json' => Json::encode($response),
                ]));
            }
        } catch (\Throwable $e) {
            if (method_exists($e, 'hasResponse')) {
                $data = Json::decode((string)$e->getResponse()->getBody());
                $message = $data['error']['errorMessage'] ?? $e->getMessage();

                Provider::error($this, Craft::t('postie', 'API error: “{message}” {file}:{line}', [
                    'message' => $message,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]));
            } else {
                Provider::error($this, Craft::t('postie', 'API error: “{message}” {file}:{line}', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]));
            }
        }

        return $response;
    }


    // Private Methods
    // =========================================================================

    private function _getClient()
    {
        if ($this->_client) {
            return $this->_client;
        }

        $url = 'https://api.sendle.com/api/';

        if ($this->getSetting('useSandbox')) {
            $url = 'https://sandbox.sendle.com/api/';
        }

        return $this->_client = Craft::createGuzzleClient([
            'base_uri' => $url,
            'auth' => [$this->getSetting('sendleId'), $this->getSetting('apiKey')],
        ]);
    }

    private function _request(string $method, string $uri, array $options = [])
    {
        $response = $this->_getClient()->request($method, ltrim($uri, '/'), $options);

        return Json::decode((string)$response->getBody());
    }

}

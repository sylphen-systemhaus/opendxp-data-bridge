<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Geo\GeoCoordinate;
use Geocoder\Provider\GoogleMaps\GoogleMaps;
use Geocoder\Provider\GoogleMaps\Model\GoogleAddress;
use Geocoder\Provider\Nominatim\Nominatim;
use Geocoder\Query\GeocodeQuery;
use Http\Discovery\HttpClientDiscovery;
use InvalidArgumentException;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Tool;

class GeopointMapper extends AbstractFieldMapper
{
    private $geocoder;
    
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\Geopoint ||
            $fieldDefinition instanceof Data\Geocoordinates ||
            $fieldDefinition->getFieldtype() === 'geopoint' ||
            $fieldDefinition->getFieldtype() === 'geocoordinates';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if ($value instanceof \OpenDxp\Model\DataObject\Data\GeoCoordinates || $value instanceof \OpenDxp\Model\DataObject\Data\GeoPoint) {
            return $value;
        }

        try {
            $coordinate = new GeoCoordinate($value);
            $value = new \OpenDxp\Model\DataObject\Data\GeoCoordinates($coordinate->getLatitude(), $coordinate->getLongitude());
        } catch (InvalidArgumentException $e) {
            if (!$value || !is_string($value)) {
                $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
                return $value;
            }

            $cacheKey = 'import_geocode_'.md5($value);
            $cacheContent = Helper::getFromCache($cacheKey);
            if ($cacheContent) {
                $value = $cacheContent;
            } else {
                try {
                    $geocoder = $this->getGeocoder();

                    $result = $geocoder->geocodeQuery(GeocodeQuery::create($value));

                    if ($result->count() > 0) {
                        /** @var GoogleAddress $geocoderResult */
                        $geocoderResult = $result->get(0);
                        $value = new \OpenDxp\Model\DataObject\Data\GeoCoordinates($geocoderResult->getCoordinates()->getLatitude(), $geocoderResult->getCoordinates()->getLongitude());
                        Helper::saveInCache($cacheKey, $value);
                    } else {
                        $this->importer->getLogger()->error('Geocoding failed.');
                        $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
                    }
                } catch (\Throwable $e) {
                    $this->importer->getLogger()->error('Geocoding failed: '.$e->getMessage());
                    $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
                }
            }
        }

        return $value;
    }

    /**
     * @return GoogleMaps|Nominatim
     */
    private function getGeocoder()
    {
        if ($this->geocoder === null) {
            $httpClient = HttpClientDiscovery::find();
            if (!empty(Helper::getPimcoreSystemConfiguration('services')['google']['simple_api_key'])) {
                $this->geocoder = new GoogleMaps($httpClient, null, Helper::getPimcoreSystemConfiguration('services')['google']['simple_api_key']);
            } else {
                $hostname = parse_url(Helper::getHostUrl(), PHP_URL_HOST);
                if(empty($hostname)) {
                    $hostname = Helper::getPimcoreSystemConfiguration('general')['domain'];
                }
                if (empty($hostname)) {
                    $hostname = Tool::getHostname();
                }
                $userAgent = 'Pimcore '.$hostname;
                $userListing = new \OpenDxp\Model\User\Listing();
                $userListing->addConditionParam('admin=1');

                $adminMailAddresses = [];
                foreach ($userListing as $user) {
                    if ($user->getEmail()) {
                        $adminMailAddresses[] = $user->getEmail();
                    }
                }

                if (count($adminMailAddresses) > 0) {
                    $userAgent .= ', admin emails: '.implode(',', $adminMailAddresses);
                }
                $this->geocoder = Nominatim::withOpenStreetMapServer($httpClient, $userAgent);
            }
        }
        return $this->geocoder;
    }
}
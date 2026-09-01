<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use ArrayIterator;
use Exception;
use IteratorAggregate;
use OpenDxp\Model\AbstractModel;
use Traversable;

class ParameterBag extends AbstractModel implements ParameterBagInterface, IteratorAggregate
{
    /** @var array */
    private $parameters;

    /** @var array */
    private $fields = [];

    /**
     * @param array $parameters
     */
    public function __construct(array $parameters)
    {
        $parameters = array_map(static function ($parameter) {
            return Importer::decodeJsonIfPossible($parameter);
        }, $parameters);

        $this->parameters = $parameters;
    }

    /**
     * @param string $name
     * @param array $arguments
     *
     * @return mixed|void
     *
     * @throws \Exception
     */
    public function __call($name, $arguments)
    {
        if (substr($name, 0, 3) === 'get') {
            $key = substr($name, 3);

            $parameters = $this->parameters;
            $indexes = array_reverse(array_keys($parameters)); // later array keys have higher priority
            $idx = array_search($key, $indexes, true);

            if ($idx !== false) {
                return $parameters[$indexes[$idx]] ?? null;
            }

            $parameters = array_change_key_case($this->parameters, CASE_LOWER);
            $indexes = array_reverse(array_keys($parameters)); // later array keys have higher priority
            $idx = array_search(strtolower($key), $indexes, true);

            if ($idx !== false) {
                return $parameters[$indexes[$idx]] ?? null;
            }
            
            throw new \Exception("Requested data $key not available");
        }

        throw new \Exception("Method not supported");
    }

    public function fieldExists($field) {
        if(array_key_exists($field, array_keys($this->parameters))) {
            return true;
        }

        return array_key_exists(strtolower($field), array_change_key_case($this->parameters, CASE_LOWER));
    }

    public function __toString() {
        return json_encode($this->parameters);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->parameters);
    }
}
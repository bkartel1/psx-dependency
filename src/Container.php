<?php
/*
 * PSX is a open source PHP framework to develop RESTful APIs.
 * For the current version and informations visit <http://phpsx.org>
 *
 * Copyright 2010-2018 Christoph Kappestein <christoph.kappestein@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace PSX\Dependency;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;

/**
 * A simple and fast PSR container implementation
 *
 * @author  Christoph Kappestein <christoph.kappestein@gmail.com>
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @link    http://phpsx.org
 */
class Container implements ContainerInterface
{
    /**
     * @var array
     */
    protected $factories;

    /**
     * @var array
     */
    protected $services;

    /**
     * @var array
     */
    protected $parameters;

    public function __construct()
    {
        $this->factories  = [];
        $this->services   = [];
        $this->parameters = [];
    }

    /**
     * @param string $name
     * @param mixed $object
     */
    public function set($name, $object)
    {
        $name = self::normalizeName($name);

        if ($object instanceof \Closure) {
            $this->factories[$name] = $object;
        } else {
            $this->services[$name] = $object;
        }
    }

    /**
     * @param string $name
     * @return mixed
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function get($name)
    {
        $name = self::normalizeName($name);

        if (!isset($this->services[$name])) {
            if (isset($this->factories[$name])) {
                $this->services[$name] = call_user_func_array($this->factories[$name], [$this]);
            } elseif (method_exists($this, $method = 'get' . $name)) {
                $this->services[$name] = $this->$method();
            } else {
                throw new NotFoundException('Service ' . $name . ' not defined');
            }
        }

        return $this->services[$name];
    }

    /**
     * @param string $name
     * @return boolean
     */
    public function has($name)
    {
        $name = self::normalizeName($name);

        return isset($this->services[$name]) || isset($this->factories[$name]) || method_exists($this, 'get' . $name);
    }

    /**
     * @param string $name
     * @return boolean
     */
    public function initialized($name)
    {
        $name = self::normalizeName($name);

        return isset($this->services[$name]);
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function setParameter($name, $value)
    {
        $name = strtolower($name);

        $this->parameters[$name] = $value;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function getParameter($name)
    {
        $name = strtolower($name);

        if ($this->hasParameter($name)) {
            return $this->parameters[$name];
        } else {
            throw new InvalidArgumentException('Parameter ' . $name . ' not set');
        }
    }

    /**
     * @param string $name
     * @return boolean
     */
    public function hasParameter($name)
    {
        $name = strtolower($name);

        return isset($this->parameters[$name]);
    }

    /**
     * Returns all available service ids of this container
     *
     * @return array
     * @throws \ReflectionException
     */
    public function getServiceIds()
    {
        $services  = [];
        $reserved  = ['get', 'getParameter', 'getServiceIds', 'getReturnType'];
        $container = new \ReflectionClass($this);

        foreach ($this->factories as $name => $factory) {
            $services[] = self::underscore($name);
        }

        foreach ($container->getMethods() as $method) {
            if (!in_array($method->name, $reserved) && preg_match('/^get(.+)$/', $method->name, $match)) {
                $services[] = self::underscore($match[1]);
            }
        }

        sort($services);

        return $services;
    }

    /**
     * Tries to determine the return type of a service. At first we try to
     * determine the type from the return annotation which is in most cases
     * more useful because it could specify an interface instead of an concrete
     * implementation. As fallback we get an instance of the service and return
     * the type
     *
     * @param string $name
     * @return string
     * @throws \ReflectionException
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getReturnType($name)
    {
        $container = new ReflectionClass($this);

        try {
            $method = $container->getMethod('get' . self::normalizeName($name));
            $doc    = $method->getDocComment();
            
            if (!empty($doc)) {
                preg_match('/@return ([a-zA-Z0-9_\x7f-\xff\x5c]+)/', $doc, $matches);

                if (isset($matches[1])) {
                    return $matches[1];
                }
            }
        } catch (ReflectionException $e) {
            // method does not exist
        }

        // as fallback we get the service and return the used type
        $service = $this->get($name);

        if (is_object($service)) {
            return get_class($service);
        } else {
            return gettype($service);
        }
    }

    /**
     * @param string $name
     * @return string
     */
    public static function normalizeName($name)
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }

    /**
     * @param string $id
     * @return string
     */
    public static function underscore($id)
    {
        return strtolower(preg_replace(array('/([A-Z]+)([A-Z][a-z])/', '/([a-z\d])([A-Z])/'), array('\\1_\\2', '\\1_\\2'), strtr($id, '_', '.')));
    }
}

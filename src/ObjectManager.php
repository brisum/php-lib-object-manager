<?php

namespace Brisum\Lib;

use ReflectionMethod;
use ReflectionParameter;

class ObjectManager
{
    /**
     * Object manager instance.
     *
     * @var ObjectManager
     */
    protected static $instance = null;

    /**
     * @var array
     */
    protected $preferences;

    /**
     * @var array
     */
    protected $virtualTypes;

    /**
     * @var array
     */
    protected $types;

    /**
     * Pool of shared instances.
     *
     * @var array
     */
    protected $sharedInstances = [];

    /**
     * @constructor
     * @param array $config
     * @param array $sharedInstances
     */
    public function __construct(array $config, array $sharedInstances = [])
    {
        $this->preferences = $config['preference'];
        $this->virtualTypes = $config['virtualType'];
        $this->types = $config['type'];

        $this->sharedInstances = [
            get_class($this) => $this
        ];
        foreach ($sharedInstances as $class => $object) {
            $class = ltrim($class, '\\');
            $this->sharedInstances[$class] = $object;
        }
    }

    /*
     * Create object by class name.
     *
     * @param string $class
     * @param array $arguments
     * @return mixed
     */
    public function create($class, array $arguments = [])
    {
        $instanceType = $this->getInstanceType($class);
        $instanceArguments = $this->getInstanceArguments($class, $arguments);
        $reflection = new \ReflectionClass($instanceType);
        $constructor = $reflection->getConstructor();

        if (null === $constructor) {
            $instance = new $instanceType();
        } else {
            $args = $this->resolveArguments($constructor, $instanceArguments);
            $instance = $reflection->newInstanceArgs($args);
        }

        return $instance;
    }

    /**
     * Get object from shared instances by class name.
     *
     * @param string $class
     * @return mixed
     */
    public function get($class)
    {
        $class = ltrim($class, '\\');

        if (!isset($this->sharedInstances[$class])) {
            $this->sharedInstances[$class] = $this->create($class);
        }
        return $this->sharedInstances[$class];
    }

    protected function getInstanceType($type)
    {
        if (isset($this->preferences[$type])) {
            return $this->preferences[$type];
        }

        if (isset($this->virtualTypes[$type]['type'])) {
            return $this->virtualTypes[$type]['type'];
        }

        return $type;
    }

    protected function isShared($class)
    {
        $typeConfig = [];

        if (isset($this->preferences[$class])) {
            $class = $this->preferences[$class];
        }

        if (isset($this->virtualTypes[$class])) {
            $typeConfig = $this->virtualTypes[$class];
        } elseif (isset($this->types[$class])) {
            $typeConfig = $this->types[$class];
        }

        return isset($typeConfig['shared']) && $typeConfig['shared'];
    }

    protected function getInstanceArguments($class, array $arguments = [])
    {
        $typeConfig = [];

        if (isset($this->preferences[$class])) {
            $class = $this->preferences[$class];
        }

        if (isset($this->virtualTypes[$class])) {
            $typeConfig = $this->virtualTypes[$class];
        } elseif (isset($this->types[$class])) {
            $typeConfig = $this->types[$class];
        }

        if (isset($typeConfig['arguments'])) {
            foreach ($typeConfig['arguments'] as $argumentName => $argumentConfig) {
                if (isset($arguments[$argumentName])) {
                    continue;
                }

                $argumentType = isset($argumentConfig['type']) ? $argumentConfig['type'] : null;
                $shared = isset($argumentConfig['shared']) && $argumentConfig['shared'];

                if ('object' == $argumentType) {
                    if (isset($this->sharedInstances[$argumentConfig['value']])) {
                        $arguments[$argumentName] = $this->sharedInstances[$argumentConfig['value']];
                    } else {
                        $arguments[$argumentName] = $shared
                            ? $this->get($argumentConfig['value'])
                            : $this->create($argumentConfig['value']);
                    }
                    continue;
                }

                $arguments[$argumentName] = $argumentConfig['value'];
            }
        }

        return $arguments;
    }


    /**
     * Invoke method of object.
     *
     * @param mixed $object
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public function invoke($object, $method, array $arguments = [])
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $args = $this->resolveArguments($method, $arguments);

        return $method->invokeArgs($object, $args);
    }

    /**
     * Resolve argument of method.
     *
     * @param ReflectionMethod $method
     * @param array $arguments
     * @return array
     */
    protected function resolveArguments(
        ReflectionMethod $method,
        array $arguments
    ) {
        $params = $method->getParameters();
        $result = [];

        foreach ($params as $param) {
            /** @var ReflectionParameter $param */
            if (isset($arguments[$param->name])) {
                $result[$param->name] = $arguments[$param->name];
                continue;
            }

            $class = $param->getClass();
            if ($class) {
                $className = $class->getName();
                if ($this->isShared($className)) {
                    $result[$className] = isset($this->sharedInstances[$className])
                        ? $this->sharedInstances[$className]
                        : $this->get($className);
                } else {
                    $result[$className] = $this->create($className);
                }
            }
        }

        return $result;
    }

    /**
     * Set object manager instance.
     *
     * @param ObjectManager $objectManager
     * @return void
     */
    public static function setInstance(ObjectManager $objectManager)
    {
        self::$instance = $objectManager;
    }

    /**
     * Get object manager instance.
     *
     * @return ObjectManager
     */
    public static function getInstance()
    {
        return self::$instance;
    }
}

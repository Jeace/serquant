<?php
/**
 * This file is part of the Serquant library.
 *
 * PHP version 5.3
 *
 * @category Serquant
 * @package  Doctrine
 * @author   Guillaume Oriol <goriol@serquant.com>
 * @license  http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @link     http://www.serquant.com/
 */
namespace Serquant\Doctrine\DependencyInjection;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\EventManager;
use Serquant\Doctrine\Exception\InvalidArgumentException;
use Serquant\Doctrine\Logger;

/**
 * Factory used by the service container (DependencyInjection) to bootstrap
 * Doctrine ORM and return an entity manager instance.
 *
 * @category Serquant
 * @package  Doctrine
 * @author   Guillaume Oriol <goriol@serquant.com>
 * @license  http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @link     http://www.serquant.com/
 * @todo     Replace this factory by the one implemented for Symfony.
 */
class EntityManagerFactory
{
    /**
     * Doctrine configuration
     * @var Configuration
     */
    private $config;

    /**
     * Doctrine event manager
     * @var EventManager
     */
    private $eventManager = null;

    /**
     * Doctrine entity manager
     * @var EntityManager
     */
    private $em;

    /**
     * Factory method to be called by the service container in order to get
     * an entity manager instance.
     *
     * Sample configuration file:
     * <pre>
     * services:
     *   doctrine:
     *     class: Doctrine\ORM\EntityManager
     *     factory_class: Serquant\Doctrine\DependencyInjection\EntityManagerFactory
     *     factory_method: get
     *     arguments: [%doctrine_config%]
     * </pre>
     *
     * @param array $config Service configuration options
     * @return EntityManager
     */
    public static function get($config)
    {
        $factory = new EntityManagerFactory($config);
        return $factory->getEntityManager();
    }

    /**
     * Construct a Doctrine entity manager according to configuration options.
     *
     * @param array $options Service configuration options
     * @return void
     */
    private function __construct($options)
    {
        $this->config = new Configuration();

        $this->initMetadataDriver($options);
        $this->initMetadataCache($options);
        $this->initQueryCache($options);
        $this->initProxy($options);
        $this->initLogger($options);
        $this->initType($options);

        $connection = $this->getConnection($options);
        $this->em = EntityManager::create(
            $connection,
            $this->config,
            $this->eventManager
        );
    }

    /**
     * Get the entity manager instance
     *
     * @return EntityManager
     */
    public function getEntityManager()
    {
        return $this->em;
    }

    /**
     * Configure the metadata driver.
     *
     * This is a REQUIRED configuration option.
     *
     * @param array $options Service configuration options
     * @return void
     */
    protected function initMetadataDriver(array $options)
    {
        if (!array_key_exists('metadata', $options)) {
            throw new InvalidArgumentException(
                'Doctrine metadata configuration is undefined.'
            );
        }
        $metadata = $options['metadata'];

        if ((!array_key_exists('mappingPaths', $metadata))
            || (!array_key_exists('driver', $metadata))
        ) {
            throw new InvalidArgumentException(
                'Either \'mappingPaths\' or \'driver\' metadata is undefined ' .
                'in Doctrine metadata configuration.'
            );
        }

        $paths = $metadata['mappingPaths'];
        if (!is_array($paths)) {
            $paths = array($paths);
        }
        foreach ($paths as $key => $value) {
            $paths[$key] = realpath($value);
        }

        $driver = strtolower($metadata['driver']);
        switch ($driver) {
            case 'annotation':
                $driverImpl = $this->getAnnotationDriver($paths, $metadata);
                break;

            case 'xml':
                $driverImpl = new \Doctrine\ORM\Mapping\Driver\XmlDriver($paths);
                break;

            case 'yaml':
                $driverImpl = new \Doctrine\ORM\Mapping\Driver\YamlDriver($paths);
                break;

            default:
                throw new InvalidArgumentException(
                    "Invalid Doctrine metadata driver: $driver"
                );
        }
        $this->config->setMetadataDriverImpl($driverImpl);
    }

    /**
     * Get an annotation driver with a correctly configured annotation reader.
     *
     * @param array $paths Annotation driver paths
     * @param array $options Metadata options
     * @return Mapping\Driver\AnnotationDriver
     * @todo Remove this function and restore the original call to
     * $this->config->newDefaultAnnotationDriver($paths) in initMetadataDriver()
     * once https://github.com/guillaumeoriol/serquant/issues/12 has been fixed.
     */
    protected function getAnnotationDriver($paths, $options)
    {
        // Register the ORM Annotations in the AnnotationRegistry
        if (isset($options['annotationsFile'])) {
            AnnotationRegistry::registerFile($options['annotationsFile']);
        }

        $reader = new AnnotationReader();
        if (isset($options['namespaceAlias'])
            && (is_array($options['namespaceAlias']))
        ) {
            foreach ($options['namespaceAlias'] as $namespace => $alias) {
                $reader->setAnnotationNamespaceAlias($namespace, $alias);
            }
        }

        $reader->setDefaultAnnotationNamespace('Doctrine\ORM\Mapping\\');
        $reader->setIgnoreNotImportedAnnotations(true);
        $reader->setEnableParsePhpImports(false);
        $reader = new \Doctrine\Common\Annotations\CachedReader(
            new \Doctrine\Common\Annotations\IndexedReader($reader),
            new \Doctrine\Common\Cache\ArrayCache()
        );
        return new AnnotationDriver($reader, (array) $paths);
    }

    /**
     * Configure the metadata cache. This option is RECOMMENDED.
     *
     * @param array $options Service configuration options
     * @return void
     */
    protected function initMetadataCache(array $options)
    {
        if (isset($options['cache']) && isset($options['cache']['metadata'])) {
            $name = strtolower($options['cache']['metadata']);
            $cache = $this->getCache($name);
            $this->config->setMetadataCacheImpl($cache);
        }
    }

    /**
     * Configure the query cache. This option is RECOMMENDED.
     *
     * @param array $options Service configuration options
     * @return void
     */
    protected function initQueryCache(array $options)
    {
        if (isset($options['cache']) && isset($options['cache']['query'])) {
            $name = strtolower($options['cache']['query']);
            $cache = $this->getCache($name);
            $this->config->setQueryCacheImpl($cache);
        }
    }

    /**
     * Get a Doctrine cache object from its name.
     *
     * @param string $name Cache name
     * @return \Doctrine\Common\Cache\AbstractCache
     */
    protected function getCache($name)
    {
        switch($name) {
            case 'apc':
                $cache = new \Doctrine\Common\Cache\ApcCache();
                break;

            case 'memcache':
                $cache = new \Doctrine\Common\Cache\MemcacheCache();
                break;

            case 'xcache':
                $cache = new \Doctrine\Common\Cache\XcacheCache();
                break;

            case 'array':
                $cache = new \Doctrine\Common\Cache\ArrayCache();
                break;

            default:
                throw new InvalidArgumentException(
                    "Invalid cache driver specified: $name"
                );
        }
        return $cache;
    }

    /**
     * Configure the proxy options. Proxy directory and namespace
     * are REQUIRED options.
     *
     * @param array $options Service configuration options
     * @return void
     */
    protected function initProxy(array $options)
    {
        if (!array_key_exists('proxy', $options)) {
            throw new InvalidArgumentException(
                'Doctrine proxy configuration is undefined.'
            );
        }
        $proxy = $options['proxy'];

        if (!isset($proxy['directory']) || !isset($proxy['namespace'])) {
            throw new InvalidArgumentException(
                'Either directory or namespace option is undefined ' .
                'in Doctrine proxy configuration.'
            );
        }

        $directory = $proxy['directory'];
        $namespace = $proxy['namespace'];
        $this->config->setProxyDir(realpath($directory));
        $this->config->setProxyNamespace($namespace);

        if (array_key_exists('autogenerate', $proxy)
            && ((bool) $proxy['autogenerate'])
        ) {
            $this->config->setAutoGenerateProxyClasses(true);
        } else {
            $this->config->setAutoGenerateProxyClasses(false);
        }
    }

    /**
     * Get connection options. Adapter and corresponding parameters
     * are REQUIRED options.
     *
     * @param array $options Service configuration options
     * @return array
     */
    protected function getConnection(array $options)
    {
        if ((!isset($options['adapter']) && !isset($options['adapterClass']))
            || !isset($options['params'])
        ) {
            throw new InvalidArgumentException(
                'Connection configuration undefined. Unable to setup Doctrine.'
            );
        }

        $adapter = $options['params'];
        if (isset($options['adapterClass'])) {
            $adapter['driverClass'] = $options['adapterClass'];
        } else {
            $adapter['driver'] = $options['adapter'];
        }

        if (isset($options['event'])) {
            $subscribers = $options['event'];
            if (!is_array($subscribers)) {
                $subscribers = array($subscribers);
            }

            foreach ($subscribers as $subscriber) {
                $this->addSubscriber($subscriber);
            }
        }
        return $adapter;
    }

    /**
     * Get Doctrine event manager
     *
     * @return EventManager
     */
    protected function getEventManager()
    {
        if ($this->eventManager === null) {
            $this->eventManager = new EventManager();
        }

        return $this->eventManager;
    }

    /**
     * Add a subscriber to the event manager.
     *
     * @param array $options Service configuration options
     * @return void
     * @throws InvalidArgumentException
     */
    protected function addSubscriber(array $options)
    {
        if (isset($options['class'])) {
            $className = $options['class'];
            if (isset($options['args'])) {
                $class = new \ReflectionClass($className);
                $args = $options['args'];
                if (!is_array($args)) {
                    $args = array($args);
                }
                $subscriber = $class->newInstanceArgs($args);
            } else {
                $subscriber = new $className;
            }
            if (!$subscriber instanceof \Doctrine\Common\EventSubscriber) {
                throw new InvalidArgumentException(
                    "The class defined as an event subscriber ($className) " .
                    'is not an instance of \Doctrine\Common\EventSubscriber' .
                    'Unable to setup Doctrine connection.'
                );
            }
            $this->getEventManager()->addEventSubscriber($subscriber);
        }
    }

    /**
     * Configure the optional logger.
     *
     * @param array $options Service configuration options
     * @return void
     */
    protected function initLogger(array $options)
    {
        if (isset($options['log'])) {
            $logger = new Logger($options['log']);
            $this->config->setSQLLogger($logger);
        }
    }

    /**
     * Configure the optional custom types.
     *
     * @param array $options Service configuration options
     * @return void
     */
    protected function initType(array $options)
    {
        if (isset($options['type'])) {
            $customTypes = $options['type'];
            if (!is_array($customTypes)) {
                throw new InvalidArgumentException(
                    'Doctrine custom types shall be an array whose key' .
                    'is the name used in field metadata and value is ' .
                    'the corresponding fully qualified class name.'
                );
            }

            foreach ($customTypes as $name => $className) {
                Type::addType($name, $className);
            }
        }
    }
}
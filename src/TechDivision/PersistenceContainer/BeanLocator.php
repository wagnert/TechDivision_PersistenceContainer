<?php

/**
 * TechDivision\PersistenceContainer\BeanLocator
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * PHP version 5
 *
 * @category  Library
 * @package   TechDivision_PersistenceContainer
 * @author    Tim Wagner <tw@techdivision.com>
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_PersistenceContainer
 * @link      http://www.appserver.io
 */

namespace TechDivision\PersistenceContainer;

use TechDivision\PersistenceContainer\BeanManager;
use TechDivision\PersistenceContainerProtocol\RemoteMethod;
use TechDivision\EnterpriseBeans\Annotations\Stateful;
use TechDivision\EnterpriseBeans\Annotations\Singleton;
use TechDivision\EnterpriseBeans\Annotations\Stateless;
use TechDivision\EnterpriseBeans\Annotations\MessageDriven;
use TechDivision\EnterpriseBeans\Annotations\PostConstruct;

/**
 * The bean resource locator implementation.
 *
 * @category  Library
 * @package   TechDivision_PersistenceContainer
 * @author    Tim Wagner <tw@techdivision.com>
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_PersistenceContainer
 * @link      http://www.appserver.io
 */
class BeanLocator implements ResourceLocator
{

    /**
     * Tries to locate the bean that handles the request and returns the instance
     * if one can be found.
     *
     * @param \TechDivision\PersistenceContainer\BeanManager           $beanManager  The bean manager instance
     * @param \TechDivision\PersistenceContainer\Protocol\RemoteMethod $remoteMethod The remote method call request
     * @param array                                                    $args         The arguments passed to the session beans constructor
     *
     * @return object The requested bean instance
     * @see \TechDivision\PersistenceContainer\ResourceLocator::locate()
     */
    public function locate(BeanManager $beanManager, RemoteMethod $remoteMethod, array $args = array())
    {

        // load the information to locate the requested bean
        $className = $remoteMethod->getClassName();
        $sessionId = $remoteMethod->getSessionId();

        // lookup the requested bean
        return $this->lookup($beanManager, $className, $sessionId, $args);
    }

    /**
     * Runs a lookup for the session bean with the passed class name and
     * session ID.
     *
     * If the passed class name is a session bean an instance
     * will be returned.
     *
     * @param \TechDivision\PersistenceContainer\BeanManager $beanManager The bean manager instance
     * @param string                                         $className   The name of the session bean's class
     * @param string                                         $sessionId   The session ID
     * @param array                                          $args        The arguments passed to the session beans constructor
     *
     * @return object The requested session bean
     * @throws \TechDivision\PersistenceContainer\InvalidBeanTypeException Is thrown if passed class name is no session bean or is a entity bean (not implmented yet)
     */
    public function lookup(BeanManager $beanManager, $className, $sessionId = null, array $args = array())
    {

        // get the reflection class for the passed class name
        $reflectionClass = $beanManager->newReflectionClass($className);

        // @Stateful
        if ($reflectionClass->hasAnnotation(Stateful::ANNOTATION)) {

            // try to load the stateful session bean from the bean manager
            if ($instance = $beanManager->lookupStatefulSessionBean($sessionId, $className)) {
                return $instance;
            }

            // if not create a new instance and return it
            $instance = $beanManager->newInstance($className, $sessionId, $args);

            // we've to check for @PostConstruct method annotations
            foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {

                // if we found a @PostConstruct annotation, invoke the method
                if ($reflectionMethod->hasAnnotation(PostConstruct::ANNOTATION)) {
                    $reflectionMethod->invoke($instance); // method MUST has no parameters
                }
            }

            // return the instance
            return $instance;
        }

        // @Singleton
        if ($reflectionClass->hasAnnotation(Singleton::ANNOTATION)) {

            // try to load the singleton session bean from the bean manager
            if ($instance = $beanManager->lookupSingletonSessionBean($className)) {
                return $instance;
            }

            // singleton session beans MUST extends \Stackable
            if (is_subclass_of($className, '\Stackable') === false) {
                throw new \Exception(sprintf('Singleton session bean %s MUST extend \Stackable', $className));
            }

            // if not create a new instance and return it
            $instance = $beanManager->newInstance($className, $sessionId, $args);

            // add the singleton session bean to the container
            $beanManager->getSingletonSessionBeans()->set($className, $instance);

            // we've to check for @PostConstruct method annotations
            foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {

                // if we found a @PostConstruct annotation, invoke the method
                if ($reflectionMethod->hasAnnotation(PostConstruct::ANNOTATION)) {
                    $reflectionMethod->invoke($instance); // method MUST has no parameters
                }
            }

            // return the instance
            return $instance;
        }

        // @Stateless or @MessageDriven
        if ($reflectionClass->hasAnnotation(Stateless::ANNOTATION) ||
            $reflectionClass->hasAnnotation(MessageDriven::ANNOTATION)) {

            // if not create a new instance and return it
            $instance = $beanManager->newInstance($className, $sessionId, $args);

            // we've to check for @PostConstruct method annotations
            foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {

                // if we found a @PostConstruct annotation, invoke the method
                if ($reflectionMethod->hasAnnotation(PostConstruct::ANNOTATION)) {
                    $reflectionMethod->invoke($instance); // method MUST has no parameters
                }
            }

            // return the instance
            return $instance;
        }

        // we've an unknown bean type => throw an exception
        throw new InvalidBeanTypeException(sprintf('Try to lookup a bean %s with missing enterprise annotation', $className));
    }
}

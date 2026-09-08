<?php

/**
 * @package     plg_task_akeebacron
 * @author      Paweł Półtoraczyk <https://web-service.com.pl>
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

// no direct access
\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Pablop76\Plugin\Task\Akeebacron\Extension\Akeebacron;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            $container->lazy(Akeebacron::class, function (Container $container) {
                $plugin = new Akeebacron(
                    (array) PluginHelper::getPlugin('task', 'akeebacron')
                );
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            })
        );
    }
};

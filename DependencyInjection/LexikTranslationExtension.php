<?php

namespace Lexik\Bundle\TranslationBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 *
 * @author Cédric Girard <c.girard@lexik.fr>
 */
class LexikTranslationExtension extends Extension
{
    /**
     * (non-PHPdoc)
     * @see Symfony\Component\DependencyInjection\Extension.ExtensionInterface::load()
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $processor = new Processor();
        $configuration = new Configuration();
        $config = $processor->processConfiguration($configuration, $configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        if ($config['storage'] == 'orm') {
            $type = 'Entity';
            $container->setAlias('lexik_translation.storage_manager', 'doctrine.orm.entity_manager');
            $container->getDefinition('lexik_translation.trans_unit.subscriber')->addTag('doctrine.event_subscriber');
        } else if ($config['storage'] == 'mongodb') {
            $type = 'Document';
            $container->setAlias('lexik_translation.storage_manager', 'doctrine.odm.mongodb.document_manager');
            $container->getDefinition('lexik_translation.trans_unit.subscriber')->addTag('doctrine.common.event_subscriber');
        } else {
            throw new \RuntimeException(sprintf('Unsupported storage "%s".', $config['storage']));
        }

        // set parameters
        sort($config['managed_locales']);
        $container->setParameter('lexik_translation.managed_locales', $config['managed_locales']);
        $container->setParameter('lexik_translation.fallback_locale', $config['fallback_locale']);
        $container->setParameter('lexik_translation.base_layout', $config['base_layout']);
        $container->setParameter('lexik_translation.force_lower_case', $config['force_lower_case']);
        $container->setParameter('lexik_translation.translator.class', $config['classes']['translator']);
        $container->setParameter('lexik_translation.loader.database.class', $config['classes']['database_loader']);
        $container->setParameter('lexik_translation.trans_unit.class', sprintf('Lexik\Bundle\TranslationBundle\%s\TransUnit', $type));
        $container->setParameter('lexik_translation.translation.class', sprintf('Lexik\Bundle\TranslationBundle\%s\Translation', $type));

        $this->registerTranslatorConfiguration($config, $container);
    }

    /**
     * Register the "lexik_translation.translator" service configuration.
     *
     * @param array $config
     * @param ContainerBuilder $container
     */
    protected function registerTranslatorConfiguration(array $config, ContainerBuilder $container)
    {
        // use the Lexik translator as default translator service
        $container->setAlias('translator', 'lexik_translation.translator');

        $translator = $container->findDefinition('lexik_translation.translator');
        $translator->addMethodCall('setFallbackLocale', array($config['fallback_locale']));

        // Discover translation directories
        $dirs = array();
        foreach ($container->getParameter('kernel.bundles') as $bundle) {
            $reflection = new \ReflectionClass($bundle);
            if (is_dir($dir = dirname($reflection->getFilename()).'/Resources/translations')) {
                $dirs[] = $dir;
            }
        }

        if (is_dir($dir = $container->getParameter('kernel.root_dir').'/Resources/translations')) {
            $dirs[] = $dir;
        }

        // Register translation resources
        if ($dirs) {
            $finder = new \Symfony\Component\Finder\Finder();
            $finder->files()
                ->filter(function (\SplFileInfo $file) {
                    return 2 === substr_count($file->getBasename(), '.');
                })
                ->in($dirs);

            foreach ($finder as $file) {
                // filename is domain.locale.format
                list($domain, $locale, $format) = explode('.', $file->getBasename());

                $translator->addMethodCall('addResource', array($format, (string) $file, $locale, $domain));
            }
        }

        // add ressources from database
        $translator->addMethodCall('addDatabaseResources', array());
    }
}

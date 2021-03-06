<?php
/**
 * Instant Analytics plugin for Craft CMS 3.x
 *
 * Instant Analytics brings full Google Analytics support to your Twig templates
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\instantanalytics;

use nystudio107\instantanalytics\helpers\IAnalytics;
use nystudio107\instantanalytics\helpers\Field as FieldHelper;
use nystudio107\instantanalytics\models\Settings;
use nystudio107\instantanalytics\services\Commerce as CommerceService;
use nystudio107\instantanalytics\services\IA as IAService;
use nystudio107\instantanalytics\variables\InstantAnalyticsVariable;
use nystudio107\instantanalytics\twigextensions\InstantAnalyticsTwigExtension;

use Craft;
use craft\base\Plugin;
use craft\events\PluginEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\helpers\UrlHelper;
use craft\services\Plugins;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;

use nystudio107\seomatic\Seomatic;

use yii\base\Event;
use yii\base\Exception;

/** @noinspection MissingPropertyAnnotationsInspection */

/**
 * @author    nystudio107
 * @package   InstantAnalytics
 * @since     1.0.0
 *
 * @property  IAService       $ia
 * @property  CommerceService $commerce
 */
class InstantAnalytics extends Plugin
{
    // Constants
    // =========================================================================

    const COMMERCE_PLUGIN_HANDLE = 'commerce';
    const SEOMATIC_PLUGIN_HANDLE = 'seomatic';

    // Static Properties
    // =========================================================================

    /**
     * @var InstantAnalytics
     */
    public static $plugin;

    /**
     * @var Plugin|null
     */
    public static $commercePlugin;

    /**
     * @var Plugin|null
     */
    public static $seomaticPlugin;

    /**
     * @var string
     */
    public static $currentTemplate = '';

    /**
     * @var bool
     */
    public static $pageViewSent = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Determine if Craft Commerce is installed & enabled
        self::$commercePlugin = Craft::$app->getPlugins()->getPlugin(self::COMMERCE_PLUGIN_HANDLE);
        // Determine if SEOmatic is installed & enabled
        self::$seomaticPlugin = Craft::$app->getPlugins()->getPlugin(self::SEOMATIC_PLUGIN_HANDLE);
        // Add in our Craft components
        $this->addComponents();
        // Install our global event handlers
        $this->installEventListeners();

        Craft::info(
            Craft::t(
                'instant-analytics',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    /**
     * @inheritdoc
     */
    public function settingsHtml()
    {
        $commerceFields = [];

        if (self::$commercePlugin) {
            /**
             * TODO: pending Commerce for Craft 3
             * $productTypes = craft()->commerce_productTypes->getAllProductTypes();
             * foreach ($productTypes as $productType) {
             * $productFields = $this->_getPullFieldsFromLayoutId($productType->fieldLayoutId);
             * $commerceFields = array_merge($commerceFields, $productFields);
             * if ($productType->hasVariants) {
             * $variantFields = $this->_getPullFieldsFromLayoutId($productType->variantFieldLayoutId);
             * $commerceFields = array_merge($commerceFields, $variantFields);
             * }
             * }
             */
        }

        // Rend the settings template
        try {
            return Craft::$app->getView()->renderTemplate(
                'instant-analytics/settings',
                [
                    'settings'       => $this->getSettings(),
                    'commerceFields' => $commerceFields,
                ]
            );
        } catch (\Twig_Error_Loader $e) {
            Craft::error($e->getMessage(), __METHOD__);
        } catch (Exception $e) {
            Craft::error($e->getMessage(), __METHOD__);
        }

        return '';
    }

    /**
     * Handle the `{% hook isSendPageView %}`
     *
     * @param array &$context
     *
     * @return string|null
     */
    public function iaSendPageView(/** @noinspection PhpUnusedParameterInspection */ array &$context)
    {
        $this->sendPageView();

        return '';
    }

    // Protected Methods
    // =========================================================================

    /**
     * Add in our Craft components
     */
    protected function addComponents()
    {
        $view = Craft::$app->getView();
        // Add in our Twig extensions
        $view->registerTwigExtension(new InstantAnalyticsTwigExtension());
        // Install our template hook
        $view->hook('iaSendPageView', [$this, 'iaSendPageView']);
        // Register our variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('instantAnalytics', InstantAnalyticsVariable::class);
            }
        );
    }

    /**
     * Install our event listeners
     */
    protected function installEventListeners()
    {
        // Handler: Plugins::EVENT_AFTER_INSTALL_PLUGIN
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    $request = Craft::$app->getRequest();
                    if ($request->isCpRequest) {
                        Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('instant-analytics/welcome'))->send();
                    }
                }
            }
        );
        $request = Craft::$app->getRequest();
        // Install only for non-console site requests
        if ($request->getIsSiteRequest() && !$request->getIsConsoleRequest()) {
            $this->installSiteEventListeners();
        }
        // Install only for non-console AdminCP requests
        if ($request->getIsCpRequest() && !$request->getIsConsoleRequest()) {
            $this->installCpEventListeners();
        }
    }

    /**
     * Install site event listeners for site requests only
     */
    protected function installSiteEventListeners()
    {
        // Handler: UrlManager::EVENT_REGISTER_SITE_URL_RULES
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                Craft::debug(
                    'UrlManager::EVENT_REGISTER_SITE_URL_RULES',
                    __METHOD__
                );
                // Register our AdminCP routes
                $event->rules = array_merge(
                    $event->rules,
                    $this->customFrontendRoutes()
                );
            }
        );
        // Remember the name of the currently rendering template
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function (TemplateEvent $event) {
                self::$currentTemplate = $event->template;
            }
        );
        // Remember the name of the currently rendering template
        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            function (TemplateEvent $event) {
                $settings = InstantAnalytics::$plugin->getSettings();
                if ($settings->autoSendPageView) {
                    $this->sendPageView();
                }
            }
        );
        // Commerce-specific hooks
        if (self::$commercePlugin) {
            // TODO: pending Commerce for Craft 3
        }
    }

    /**
     * Install site event listeners for AdminCP requests only
     */
    protected function installCpEventListeners()
    {
    }

    /**
     * Return the custom frontend routes
     *
     * @return array
     */
    protected function customFrontendRoutes(): array
    {
        return [
            'instantanalytics/pageViewTrack/<filename:[-\w\.*]+>?' =>
                'instant-analytics/track/track-page-view-url',
            'instantanalytics/eventTrack/<filename:[-\w\.*]+>?' =>
                'instant-analytics/track/track-event-url',
        // Make webpack async bundle loading work out of published AssetBundles
            '/cpresources/instant-analytics/<resourceType:{handle}>/<fileName>' => 'instant-analytics/manifest/resource',
        ];
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }

    // Private Methods
    // =========================================================================

    /**
     * Send a page view with the pre-loaded IAnalytics object
     */
    private function sendPageView()
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsSiteRequest() && !$request->getIsConsoleRequest() && !self::$pageViewSent) {
            self::$pageViewSent = true;
            /** @var IAnalytics $analytics */
            $analytics = InstantAnalytics::$plugin->ia->getGlobals(self::$currentTemplate);
            // Bail if we have no analytics object
            if ($analytics === null) {
                return;
            }
            // If SEOmatic is installed, set the page title from it
            $this->setTitleFromSeomatic($analytics);
            // Send the page view
            if ($analytics) {
                $response = $analytics->sendPageview();
                Craft::info(
                    Craft::t(
                        'instant-analytics',
                        'pageView sent, response:: {response}',
                        [
                            'response' => print_r($response, true),
                        ]
                    ),
                    __METHOD__
                );
            } else {
                Craft::error(
                    Craft::t(
                        'instant-analytics',
                        'Analytics not sent because googleAnalyticsTracking is not set'
                    ),
                    __METHOD__
                );
            }
        }
    }

    /**
     * If SEOmatic is installed, set the page title from it
     *
     * @param $analytics
     */
    private function setTitleFromSeomatic(IAnalytics $analytics)
    {
        if (self::$seomaticPlugin && Seomatic::$settings->renderEnabled) {
            $titleTag = Seomatic::$plugin->title->get('title');
            if ($titleTag) {
                $titleArray = $titleTag->renderAttributes();
                if (!empty($titleArray['title'])) {
                    $analytics->setDocumentTitle($titleArray['title']);
                }
            }
        }
    }
}

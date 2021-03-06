<?php

/**
 * Pimcore
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.pimcore.org/license
 *
 * @copyright  Copyright (c) 2009-2014 pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     New BSD License
 */

namespace Pimcore\View\Helper;

use Pimcore\Model\Document;
use Pimcore\Model\Cache as CacheManager;
use Pimcore\Model\Site;

class PimcoreNavigation extends \Zend_View_Helper_Navigation
{
    /**
     * @var PimcoreNavigationController
     */
    public static $_controller;

    /**
     * @return PimcoreNavigationController
     */
    public static function getController()
    {
        if (!self::$_controller) {
            self::$_controller = new PimcoreNavigationController();
        }

        return self::$_controller;
    }

    /**
     * @param null $activeDocument
     * @param null $navigationRootDocument
     * @param null $htmlMenuIdPrefix
     * @param callable $pageCallback
     * @return $this|PimcoreNavigationController
     * @throws \Zend_View_Exception+
     */
    public function pimcoreNavigation($activeDocument = null, $navigationRootDocument = null, $htmlMenuIdPrefix = null, $pageCallback = null, $cache = true)
    {

        $controller = self::getController();

        if($activeDocument) {
            // this is the new more convenient way of creating a navigation
            $navContainer = $controller->getNavigation($activeDocument, $navigationRootDocument, $htmlMenuIdPrefix, $pageCallback, $cache);
            $this->navigation($navContainer);
            $this->setUseTranslator(false);
            $this->setInjectTranslator(false);

            // now we need to refresh the container in all helpers, since the container can change from call to call
            // see also https://www.pimcore.org/issues/browse/PIMCORE-2636 which describes this problem in detail
            foreach($this->_helpers as $helper) {
                $helper->setContainer($this->getContainer());
            }

            // just to be sure, ... load the menu helper and set the container
            $menu = $this->findHelper("menu");
            if($menu) {
                $menu->setContainer($this->getContainer());
            }

            return $this;
        } else {
            // this is the old-style navigation
            return $controller;
        }
    }

    /**
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public function __call($method, array $arguments = array())
    {
        $return = parent::__call($method, $arguments);

        // disable the translator per default, because this isn't necessary for pimcore
        if(is_object($return) && method_exists($return, "setUseTranslator")) {
            $return->setUseTranslator(false);
        }

        return $return;
    }
}

class PimcoreNavigationController
{
    /**
     * @var string
     */
    protected $_htmlMenuIdPrefix;

    /**
     * @var string
     */
    protected $_pageClass = '\\Pimcore\\Navigation\\Page\\Uri';

    /**
     * @param $activeDocument
     * @param null $navigationRootDocument
     * @param null $htmlMenuIdPrefix
     * @param callable $pageCallback
     * @return mixed|\Zend_Navigation
     * @throws \Exception
     * @throws \Zend_Navigation_Exception
     */
    public function getNavigation($activeDocument, $navigationRootDocument = null, $htmlMenuIdPrefix = null, $pageCallback = null, $cache = true)
    {
        $this->_htmlMenuIdPrefix = $htmlMenuIdPrefix;

        if (!$navigationRootDocument) {
            $navigationRootDocument = Document::getById(1);
        }

        $siteSuffix = "";
        if(Site::isSiteRequest()) {
            $site = Site::getCurrentSite();
            $siteSuffix = "__site_" . $site->getId();
        }

        $cacheKey = "navigation_" . $navigationRootDocument->getId() . $siteSuffix;
        $navigation = CacheManager::load($cacheKey);

        if(!$navigation || !$cache) {
            $navigation = new \Zend_Navigation();

            if ($navigationRootDocument->hasChilds()) {
                $rootPage = $this->buildNextLevel($navigationRootDocument, true, $pageCallback);
                $navigation->addPages($rootPage);
            }

            // we need to force caching here, otherwise the active classes and other settings will be set and later
            // also written into cache (pass-by-reference) ... when serializing the data directly here, we don't have this problem
            if($cache) {
                CacheManager::save($navigation, $cacheKey, ["output","navigation"], null, 999, true);
            }
        }

        // set active path
        $activePage = $navigation->findOneBy("realFullPath", $activeDocument->getRealFullPath());
        if(!$activePage) {
            // find by link target
            $activePage = $navigation->findOneBy("uri", $activeDocument->getRealFullPath());
        }

        if($activePage) {
            // we found an active document, so we can build the active trail by getting respectively the parent
            $this->addActiveCssClasses($activePage, true);
        } else {
            // we don't have an active document, so we try to build the trail on our own
            $allPages = $navigation->findAllBy("uri", "/.*/", true);

            foreach($allPages as $page) {
                $activeTrail = false;

                if (strpos($activeDocument->getRealFullPath(), $page->getRealFullPath() . "/") === 0) {
                    $activeTrail = true;
                }

                if($page->getDocumentType() == "link") {
                    if (strpos($activeDocument->getFullPath(), $page->getUri() . "/") === 0) {
                        $activeTrail = true;
                    }
                }

                if($activeTrail) {
                    $page->setActive(true);
                    $page->setClass($page->getClass() . " active active-trail");
                }
            }
        }

        return $navigation;
    }

    /**
     * @param \Pimcore\Navigation\Page\Uri $page
     */
    protected function addActiveCssClasses($page, $isActive = false) {
        $page->setActive(true);

        $parent = $page->getParent();
        $isRoot = false;
        $classes = "";

        if($parent instanceof \Pimcore\Navigation\Page\Uri) {
            $this->addActiveCssClasses($parent);
        } else {
            $isRoot = true;
        }

        $classes .= " active";

        if(!$isActive) {
            $classes .= " active-trail";
        }

        if ($isRoot && $isActive) {
            $classes .= " mainactive";
        }


        $page->setClass($page->getClass() . $classes);
    }

    /**
     * @param $pageClass
     * @return $this
     */
    public function setPageClass($pageClass)
    {
        $this->_pageClass = $pageClass;
        return $this;
    }

    /**
     * Returns the name of the pageclass
     * 
     * @return String
     */
    public function getPageClass()
    {
        return $this->_pageClass;
    }


    /**
     * @param Document $parentDocument
     * @return Document[]
     */
    protected function getChilds($parentDocument) {
        return $parentDocument->getChilds();
    }

    /**
     * @param $parentDocument
     * @param bool $isRoot
     * @param callable $pageCallback
     * @return array
     */
    protected function buildNextLevel($parentDocument, $isRoot = false, $pageCallback = null)
    {
        $pages = array();

        $childs = $this->getChilds($parentDocument);
        if (is_array($childs)) {
            foreach ($childs as $child) {
                $classes = "";

                if($child instanceof Document\Hardlink) {
                    $child = Document\Hardlink\Service::wrap($child);
                }

                if (($child instanceof Document\Page or $child instanceof Document\Link) and $child->getProperty("navigation_name")) {

                    $path = $child->getFullPath();
                    if ($child instanceof Document\Link) {
                        $path = $child->getHref();
                    }
                    
                    $page = new $this->_pageClass();
                    $page->setUri($path . $child->getProperty("navigation_parameters") . $child->getProperty("navigation_anchor"));
                    $page->setLabel($child->getProperty("navigation_name"));
                    $page->setActive(false);
                    $page->setId($this->_htmlMenuIdPrefix . $child->getId());
                    $page->setClass($child->getProperty("navigation_class"));
                    $page->setTarget($child->getProperty("navigation_target"));
                    $page->setTitle($child->getProperty("navigation_title"));
                    $page->setAccesskey($child->getProperty("navigation_accesskey"));
                    $page->setTabindex($child->getProperty("navigation_tabindex"));
                    $page->setRelation($child->getProperty("navigation_relation"));
                    $page->setDocument($child);

                    if ($child->getProperty("navigation_exclude") || !$child->getPublished()) {
                        $page->setVisible(false);
                    }

                    if ($isRoot) {
                        $classes .= " main";
                    }

                    $page->setClass($page->getClass() . $classes);

                    if($pageCallback) {
                        $pageCallback($page, $child);
                    }

                    if ($child->hasChilds()) {
                        $childPages = $this->buildNextLevel($child, false);
                        $page->setPages($childPages);
                    }

                    $pages[] = $page;
                }
            }
        }
        
        return $pages;
    }

}

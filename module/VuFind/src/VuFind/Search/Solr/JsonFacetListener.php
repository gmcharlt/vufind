<?php

/**
 * Solr json facet listener.
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2013.
 * Copyright (C) The National Library of Finland 2014.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  Search
 * @author   Vaclav Rosecky <xrosecky@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
namespace VuFind\Search\Solr;

use VuFindSearch\Backend\BackendInterface;

use Zend\EventManager\SharedEventManagerInterface;
use Zend\EventManager\EventInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * Solr hierarchical facet handling listener.
 *
 * @category VuFind2
 * @package  Search
 * @author   Vaclav Rosecky <xrosecky@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class JsonFacetListener
{

    const SOLR_LOCAL_PARAMS = "/(\\{[^\\}]*\\})*(\S+)/";

    const UNLIMITED_FACET_LIMIT = 10000;

    /**
     * Backend.
     *
     * @var BackendInterface
     */
    protected $backend;

    /**
     *
     *
     */
    protected $nestedFacets = [];

    /**
     *
     *
     */
    protected $orFacets = [];

    protected $allFacetsAreOr = false;

    protected $enabledForAllFacets = false;

    protected $parentCount = true;

    /**
     * Logger.
     *
     * @var Zend\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Constructor.
     *
     * @param BackendInterface $backend   Backend
     * @param string           $fieldList Field(s) to highlight (hl.fl param)
     *
     * @return void
     */
    public function __construct(BackendInterface $backend, \Zend\Config\Config $facetConfig)
    {
        $this->backend = $backend;
        if (isset($facetConfig->Results_Settings->orFacets)) {
            $this->orFacets = explode(',', $facetConfig->Results_Settings->orFacets);
        }
        if (isset($facetConfig->SpecialFacets->nested)) {
            $this->nestedFacets = $facetConfig->SpecialFacets->nested->toArray();
        }
        if (isset($facetConfig->SpecialFacets->nestedParentCount)) {
            $this->parentCount = $facetConfig->SpecialFacets->nestedParentCount;
        }
        if (isset($facetConfig->JSON_API) && isset($facetConfig->JSON_API->enabled) && $facetConfig->JSON_API->enabled) {
            $this->enabledForAllFacets = true;
        }
        if (!empty($this->orFacets) && $this->orFacets[0] == "*") {
            $this->allFacetsAreOr = true;
        }
    }

    /**
     * Attach listener to shared event manager.
     *
     * @param SharedEventManagerInterface $manager Shared event manager
     *
     * @return void
     */
    public function attach(SharedEventManagerInterface $manager)
    {
        $manager->attach('VuFind\Search', 'pre', [$this, 'onSearchPre']);
    }

    /**
     *
     *
     * @param EventInterface $event Event
     *
     * @return EventInterface
     */
    public function onSearchPre(EventInterface $event)
    {
        if ($event->getParam('context') != 'search') {
            return $event;
        }
        $backend = $event->getTarget();
        if ($backend === $this->backend) {
            $this->process($event);
        }
        return $event;
    }

    protected function process($event)
    {
        $params = $event->getParam('params');
        if (!$params) {
            return;
        }
        $this->transformFacetQueries($params);
        $this->transformFacets($params);
    }

    protected function transformFacets($params)
    {
        $defaultFacetLimit = $params->get('facet.limit')[0];
        $jsonFacetData = [];
        $remaining = [];
        if ($params->get('facet.field')) {
            foreach ($params->get('facet.field') as $facetField) {
                $field = $facetField;
                if (preg_match(self::SOLR_LOCAL_PARAMS, $field, $matches)) {
                    $field = $matches[2];
                }
                $isNested = in_array($field, $this->nestedFacets);
                if ($this->enabledForAllFacets || $isNested) {
                    $limit = $params->get('f.' . $field . '.facet.limit')[0];
                    if (!isset($limit)) {
                        $limit = $defaultFacetLimit;
                    }
                    $jsonFacetData[$field] = $this->getFacetConfig($field, $limit);
                } else {
                    $remaining[] = $facetField;
                }
            }
        }
        if (empty($remaining)) {
            $params->remove('facet.field');
        } else {
            $params->set('facet.field', $remaining);
        }

        if (!empty($jsonFacetData)) {
            $this->logger->info("fq: " . print_r($jsonFacetData, true));
            $params->set('json.facet', json_encode($jsonFacetData));
        }
    }

    protected function getFacetConfig($field, $limit)
    {
        $data = [
                'type' => 'terms',
                'field' => $field,
                'limit' => ($limit == -1) ? self::UNLIMITED_FACET_LIMIT : (int) $limit
        ];
        if (in_array($field, $this->nestedFacets)) {
            $data['domain'] = [ 'blockChildren' => 'merged_boolean:true' ];
            if ($this->parentCount) {
                $data['facet']  = [ 'count' => "unique(_root_)" ];
            }
        }
        $data['excludeTags'] = [ 'nested_facet_filter' ];
        $data['domain']['excludeTags'] = [ 'nested_facet_filter' ];
        if ($this->isOrFacet($field)) {
            $data['excludeTags'][] = $field . '_filter';
            $data['domain']['excludeTags'][] = $field . '_filter';
        }
        return $data;
    }

    protected function transformFacetQueries($params)
    {
        $fqs = $params->get('fq');
        $newfqs = array();
        $nestedFacets = [];
        foreach ($fqs as $fq) {
            list($field, $query) = explode(":", $fq, 2);
            $localParams = null;
            $matches = [];
            if (preg_match(self::SOLR_LOCAL_PARAMS, $field, $matches)) {
                $localParams = $matches[1];
                $field = $matches[2];
                $fq = $localParams . ' (' . $field . ':' . $query . ')';
            }
            if (in_array($field, $this->nestedFacets)) {
                if ($this->isOrFacet($field)) {
                    $nestedFacets[] = $fq;
                }
                $newfqs[] = $this->addToLocalParams($localParams, "parent which='merged_boolean:true'") . ' (' . $field . ':' . $query . ')';
            } else {
                $newfqs[] = $fq;
            }
        }
        if (count($nestedFacets) > 1) {
            $newfqs[] = "{!parent which='merged_boolean:true' tag=nested_facet_filter}( " . implode(' AND ', $nestedFacets) . " )";
        }
        $this->logger->debug("fq: " . print_r($newfqs, true));
        $params->set('fq', $newfqs);
    }

    protected function addToLocalParams($localParams, $newParam)
    {
        $localParams = trim($localParams);
        if ($localParams == null) {
            return "{!" . $newParam . "}";
        } else {
            return rtrim($localParams, "}") . " " . $newParam . "}";
        }
    }

    protected function isOrFacet($field)
    {
        return ($this->allFacetsAreOr || in_array($field, $this->orFacets));
    }

    /**
     * Set the Logger.
     *
     * @param VuFind\Log\Logger $logger Logger
     *
     * @return void
     */
    public function setLogger(\VuFind\Log\Logger $logger)
    {
        $this->logger = $logger;
    }

}
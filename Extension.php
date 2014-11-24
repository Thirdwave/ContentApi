<?php namespace Bolt\Extension\Thirdwave\ContentApi;

use Bolt\BaseExtension;
use Bolt\Content;
use Doctrine\DBAL\DBALException;
use Symfony\Component\HttpFoundation\Request;


/**
 * ContentApi extension for Bolt
 *
 * Copyright (C) 2014  Gawain Lynch
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Guido Gautier <ggautier@thirdwave.nl>
 * @copyright Copyright (c) 2014, Thirdwave
 * @license   http://opensource.org/licenses/GPL-3.0 GNU Public License 3.0
 */
class Extension extends BaseExtension
{


    protected $contenttypes;


    function getName()
    {
        return "contentapi";
    }


    function initialize()
    {
        $this->contenttypes = $this->app['config']->get('contenttypes');

        $routes = $this->app['controllers_factory'];

        // Always executed before executing the call.
        $routes->before(array($this, 'before'));

        // Returns a simple response to check if the api is working.
        $routes->match('/', array($this, 'index'));

        // Returns search results from all contenttypes.
        $routes->match('/search', array($this, 'search'));

        // Returns a number of search results from all contenttypes.
        $routes->match('/search/{amount}', array($this, 'searchAmount'))
          ->assert('amount', '[\d]+');

        // Returns unique values for a taxonomy type.
        $routes->match('/taxonomy/{taxonomytype}', array($this, 'taxonomy'));

        // Returns content for a taxonomy type and slug.
        $routes->match('/taxonomy/{taxonomytype}/{slug}', array($this, 'taxonomyContent'));

        // Returns a listing of records from a contenttype.
        $routes->match('/{contenttype}', array($this, 'listing'));

        // Returns the latest records for a contenttype.
        $routes->match('/{contenttype}/latest/{amount}', array($this, 'listingLatest'))->assert('amount', '[\d]+');

        // Returns the first records for a contenttype.
        $routes->match('/{contenttype}/first/{amount}', array($this, 'listingFirst'))->assert('amount', '[\d]+');

        // Returns search results for a contenttype.
        $routes->match('/{contenttype}/search', array($this, 'searchContenttype'));

        // Returns a number of search results for a contenttype.
        $routes->match('/{contenttype}/search/{amount}', array($this, 'searchContenttypeAmount'))->assert('amount',
          '[\d]+');

        // Returns a single record from a contenttype by slug or id.
        $routes->match('/{contenttype}/{slugOrId}', array($this, 'record'));

        // Include the major version in the api mounting point.
        $parts   = explode('.', $this->info['version']);
        $version = array_shift($parts);

        $this->app->mount($this->config['mounting_point'] . '/v' . $version, $routes);
    }


    public function before(Request $request)
    {
        $client = $request->getClientIp();

        // By default the ip of the server running the api is whitelisted. Other
        // ip addresses need to be configured te gain access.
        $whitelist   = $this->config['whitelist'] ?: array();
        $whitelist[] = $request->server->get('SERVER_ADDR');

        foreach ($whitelist as $ip) {
            if (strpos($client, $ip) !== false) {
                return null;
            }
        }

        return $this->app->json(array('status' => 403), 403);
    }


    /**
     * Simple response to check if the api is working.
     *
     * @return JsonResponse
     */
    public function index()
    {
        return $this->app->json(array('it' => 'works'));
    }


    /**
     * Returns all unique values for a taxonomy type. Also returns the number of
     * content items that have the value set.
     *
     * Query parameters:
     * order Field to order the values by. Default is name. Options are name and
     *       count. Put a minus before the field to get the reverse order.
     *
     * @param  string  $taxonomytype Slug of the taxonomy type.
     * @param  Request $request      Current request.
     * @return JsonResponse
     */
    public function taxonomy($taxonomytype, Request $request)
    {
        // Check if the taxonomy type is configured.
        if (!$this->app['storage']->getTaxonomyType($taxonomytype)) {
            return $this->app->json(array('status' => 404), 404);
        }

        // Default ordering is by name.
        $order = $request->query->get('order', $request->get('orderby', 'name'));

        // Translate the order to the correct query order statement.
        switch ($order) {
            case 'name':
                $order = 'T.name';
                break;
            case 'count':
                $order = 'results';
                break;
            case '-results': // NO BREAK
            case '-count':
                $order = 'results DESC';
                break;
            default:
                return $this->app->json(array(
                  'status' => 500,
                  'error'  => 'Invalid orderby. Options are name and count.'
                ), 500);
        }

        try {
            $values = $this->app['db']->executeQuery($this->getTaxonomyQuery($order),
              array($taxonomytype, $taxonomytype))->fetchAll();
        } catch ( DBALException $e ) {
            return $this->app->json(array('status' => 500, 'error' => $e->getMessage()), 500);
        }

        return $this->app->json(array(
          'type'   => $taxonomytype,
          'count'  => count($values),
          'values' => $values
        ));
    }


    /**
     * Returns all content for a taxonomy type and value slug.
     *
     * @param  string  $taxonomytype Taxonomy type.
     * @param  string  $slug         Slug of the taxonomy value.
     * @param  Request $request      Current request.
     * @return JsonResponse
     */
    public function taxonomyContent($taxonomytype, $slug, Request $request)
    {
        // Check if the taxonomy type is configured.
        if (!$this->app['storage']->getTaxonomyType($taxonomytype)) {
            return $this->app->json(array('status' => 404), 404);
        }

        $parameters                 = $this->getParametersFromRequest($request);
        $parameters['taxonomytype'] = $taxonomytype;
        $parameters['slug']         = $slug;

        return $this->listingResponse(null, $parameters, array(), null, 'taxonomy');
    }


    /**
     * Returns a listing of records for a contenttype.
     *
     * Query parameters:
     * type   Name of the response type. Default is listing.
     * limit  Number of records to return.
     * order  Field to order records by.
     * page   Page number.
     * where  One or multiple where statements. Syntax is: where[field]=value.
     * select Special where case to be used when filtering by a select field.
     *        Syntax is the same as where.
     *
     * @param  string  $contenttype Contenttype to get records for.
     * @param  Request $request     Current request.
     * @return JsonResponse
     */
    public function listing($contenttype, Request $request)
    {
        $type       = $request->get('type', 'listing');
        $parameters = $this->getParametersFromRequest($request);
        $where      = $this->getWhereFromRequest($request);

        return $this->listingResponse($contenttype, $parameters, $where, $contenttype, $type);
    }


    /**
     * Returns the latest published records for a contenttype. Returns the amount
     * of records that were requested.
     *
     * @param  string  $contenttype Contenttype to get the latest records for.
     * @param  integer $amount      Number of records to return.
     * @return JsonResponse
     */
    public function listingLatest($contenttype, $amount)
    {
        return $this->listingResponse($contenttype . '/latest/' . $amount);
    }


    /**
     * Returns the first published records for a contenttype. Returns the amount
     * of records that were requested.
     *
     * @param  string  $contenttype Contenttype to get the first records for.
     * @param  integer $amount      Number of records to return.
     * @return JsonResponse
     */
    public function listingFirst($contenttype, $amount)
    {
        return $this->listingResponse($contenttype . '/first/' . $amount);
    }


    /**
     * Returns a single record for a contenttype by slug or id.
     *
     * Query parameters:
     * type Name of the response type. Default is record.
     *
     * @param  string         $contenttype Contenttype to get record for.
     * @param  string|integer $slugOrId    Slug or id of the record.
     * @param  Request        $request     Current request.
     * @return JsonResponse
     */
    public function record($contenttype, $slugOrId, Request $request)
    {
        // Allow for custom set of return values.
        $type = $request->get('type', 'record');

        // Check if the contenttype is defined.
        if (!$this->app['storage']->getContenttype($contenttype)) {
            return $this->app->json(array('status' => 404), 404);
        }

        $record = $this->app['storage']->getContent($contenttype . '/' . $slugOrId);

        if (!$record) {
            return $this->app->json(array('status' => 404), 404);
        }

        return $this->app->json($this->parseRecord($record, $type));
    }


    /**
     * Returns search results.
     *
     * Query parameters:
     * type   Name of the response type. Default is search.
     * filter Search term.
     * limit  Number of records to return.
     * order  Field to order records by.
     * page   Page number.
     * where  One or multiple where statements. Syntax is: where[field]=value.
     * select Special where case to be used when filtering by a select field.
     *        Syntax is the same as where.
     *
     * @param  Request $request     Current request.
     * @param  string  $contenttype Optional contenttype name, only used internally.
     * @return JsonResponse
     */
    public function search(Request $request, $contenttype = null)
    {
        $filter = $request->get('filter');
        $type   = $request->get('type', 'search');

        if (empty($filter)) {
            return $this->app->json(array('status' => 500, 'error' => 'Missing filter.'), 500);
        }

        // Create a list of the requested contenttypes or list all contenttypes.
        $types = $request->get('contenttypes', implode(',', array_keys($this->contenttypes)));

        $query      = '(' . $types . ')/search';
        $parameters = $this->getParametersFromRequest($request);
        $where      = $this->getWhereFromRequest($request);

        // Set the search term and contenttypes.
        $parameters['filter']       = $filter;
        $parameters['contenttypes'] = explode(',', $types);

        return $this->listingResponse($query, $parameters, $where, $contenttype, $type);
    }


    /**
     * Returns search results for a contenttype.
     *
     * @see    search()
     * @param  string  $contenttype Contenttype to get search results for.
     * @param  Request $request     Current request.
     * @return JsonResponse
     */
    public function searchContenttype($contenttype, Request $request)
    {
        $request->query->set('contenttypes', $contenttype);

        return $this->search($request, $contenttype);
    }


    /**
     * Returns a given amount of search results for a contenttype.
     *
     * @see    search()
     * @param  string  $contenttype Contenttype to get search results for.
     * @param  integer $amount      Number of results to return.
     * @param  Request $request     Current request.
     * @return JsonResponse
     */
    public function searchContenttypeAmount($contenttype, $amount, Request $request)
    {
        $request->query->set('contenttypes', $contenttype);
        $request->query->set('limit', $amount);
        $request->query->set('page', 1);

        return $this->search($request, $contenttype);
    }


    /**
     * Returns a given amount of search results from all contenttypes.
     *
     * @param  integer $amount  Number of results to return.
     * @param  Request $request Current request.
     * @return JsonResponse
     */
    public function searchAmount($amount, Request $request)
    {
        $request->query->set('limit', $amount);
        $request->query->set('page', 1);

        return $this->search($request);
    }


    /**
     * Returns search results.
     *
     * @param  array $parameters Parameters for getting content.
     * @param  array $where      Extra where parameters.
     * @param  array $paging     Array passed by reference.
     * @return array
     */
    protected function getSearchResults(array $parameters, array $where, array &$paging)
    {
        $offset       = ($parameters['page'] - 1) * $parameters['limit'];
        $contenttypes = $parameters['contenttypes'];

        try {
            $result = $this->app['storage']->searchContent($parameters['filter'], $contenttypes, $where,
              $parameters['limit'], $offset);
        } catch ( DBALException $e ) {
            return $this->app->json(array('status' => 500, 'error' => $e->getMessage()), 500);
        }

        $paging = array(
          'for'          => 'search',
          'count'        => $result['no_of_results'],
          'totalpages'   => ceil($result['no_of_results'] / $parameters['limit']),
          'current'      => $parameters['page'],
          'showing_from' => $offset + 1,
          'showing_to'   => $offset + count($result['results'])
        );

        return $result['results'];
    }


    /**
     * Returns all content for a taxonomytype and value.
     *
     * @param  array $parameters Array of parameters.
     * @param  array $paging     Paging array by reference.
     * @return array
     */
    protected function getTaxonomyContent(array $parameters, array &$paging)
    {
        $taxonomytype = $parameters['taxonomytype'];
        $slug         = $parameters['slug'];
        $records      = $this->app['storage']->getContentByTaxonomy($taxonomytype, $slug, $parameters);
        $paging       = $GLOBALS['pager'][$taxonomytype . '/' . $slug];

        return $records;
    }


    /**
     * Returns the json response for a listing.
     *
     * @param  string $query       Text query,
     * @param  array  $parameters  Parameters for getting content.
     * @param  array  $where       Extra where parameters.
     * @param  string $contenttype Name of the contenttype.
     * @return JsonResponse
     */
    protected function listingResponse(
      $query,
      array $parameters = array(),
      array $where = array(),
      $contenttype = null,
      $type = 'listing'
    ) {
        // Check if a given contenttype is configured.
        if (!empty($contenttype) && !$this->app['storage']->getContenttype($contenttype)) {
            return $this->app->json(array('status' => 404), 404);
        }

        $parameters = array_merge($parameters, $where);
        $paging     = array();

        if ($type === 'search') {
            $records = $this->getSearchResults($parameters, $where, $paging);
        } else {
            if ($type === 'taxonomy') {
                $records = $this->getTaxonomyContent($parameters, $paging);
            } else {
                try {
                    $records = $this->app['storage']->getContent($query, $parameters, $paging);
                } catch ( DBALException $e ) {
                    return $this->app->json(array('status' => 500, 'error' => $e->getMessage()), 500);
                }
            }
        }

        $records = array_values($records ?: array());

        foreach ($records as &$record) {
            $record = $this->parseRecord($record, $type);
        }

        // Set the previous and next page.
        if ($paging['current'] > 1) {
            $paging['previous'] = $paging['current'] - 1;
        }

        if ($paging['current'] < $paging['totalpages']) {
            $paging['next'] = $paging['current'] + 1;
        }

        return $this->app->json(array(
          'records'     => $records,
          'paging'      => $paging,
          'contenttype' => $contenttype,
          'type'        => $type,
          'query'       => $query,
          'parameters'  => $parameters
        ));
    }


    /**
     * Parse a record into an array of values.
     *
     * @param  Content $record
     * @param  string  $type
     * @return array
     */
    protected function parseRecord(Content $record, $type = 'listing')
    {
        $columns = $this->getColumns($record, $type);
        $values  = array();

        foreach ($columns as $name => $properties) {
            if (is_numeric($name)) {
                $name       = $properties;
                $properties = array('type' => 'unknown');
            }

            if ($name === 'contenttype') {
                foreach ($this->contenttypes as $name => $contenttype) {
                    if ($record->contenttype['slug'] === $contenttype['slug']) {
                        $values['contenttype'] = $name;
                    }
                }
            } else {
                $values[$name] = $this->parseRecordValue($properties['type'], $record->values[$name]);
            }
        }

        // Include taxonomy in the results
        if (!empty($record->taxonomy)) {
            $taxonomy = array();

            foreach ($record->taxonomy as $type => $content) {
                $taxonomy[$type] = array();

                foreach ($content as $url => $label) {
                    $parts                   = explode('/', $url);
                    $taxonomy[$type][$label] = array_pop($parts);
                }
            }

            $values['taxonomy'] = $taxonomy;
        }

//    if ( $related && !empty($record->relation) ) {
//      foreach ( $record->relation as $relation => $records ) {
//        if ( !isset($this->config['contenttypes']) || !isset($this->config['contenttypes'][$contenttype['slug']]) ) {
//          return $values;
//        }
//
//        $config = $this->config['contenttypes'][$contenttype['slug']];
//
//        if ( !isset($config['related']) || !$config['related'] ) {
//          return $values;
//        }
//
//        if ( is_array($config['related']) && !in_array($relation, $config['related']) ) {
//          continue;
//        }
//
//        if ( !isset($values['related']) ) {
//          $values['related'] = array();
//        }
//
//        $values['related'][$relation] = array();
//
//        foreach ( $records as $id ) {
//          $content = $this->app['storage']->getContent($relation . '/' . $id);
//
//          $values['related'][$relation][] = $this->parseRecord($content, $type, false);
//        }
//      }
//    }

        return $values;
    }


    /**
     * Parse a record value. Returns the parsed value.
     *
     * @param  $type        Type of value to parse.
     * @param  $value       Value to parse.
     * @return array|string
     */
    protected function parseRecordValue($type, $value)
    {
        switch ($type) {
            case 'file': // NO BREAK
                $value = array('file' => $value);
            case 'image':
                if (empty($value['file'])) {
                    return $value;
                }

                $parts = explode('/', $value['file']);

                $value = array(
                  'file'     => $value['file'],
                  'filename' => array_pop($parts),
                  'path'     => substr($this->app['paths']['files'], 1) . $value['file'],
                  'host'     => $this->app['paths']['hosturl'] . '/'
                );

                break;
        }

        return $value;
    }


    /**
     * Returns the columns of a record for the given result type.
     *
     * @param  Content $record Content record to get columns for.
     * @param  string  $type   Type of result. Default is 'listing'.
     * @return array
     */
    protected function getColumns(Content $record, $type = 'listing')
    {
        $contenttype = $record->contenttype;
        $columns     = $this->getBaseColumns($record);

        // Check for contenttype specific list of fields for the given return type.
        if (isset($this->config['contenttypes']) && isset($this->config['contenttypes'][$contenttype['slug']])) {
            $config = $this->config['contenttypes'][$contenttype['slug']];

            if (isset($config[$type])) {
                $columns = array_merge($columns, $config[$type]);
            } else {
                $columns = array_merge($columns, $contenttype['fields']);
            }
        } else {
            $columns = array_merge($columns, $contenttype['fields']);
        }

        return $columns;
    }


    /**
     * Returns the base columns for a record.
     *
     * @param  Content $record
     * @return array
     */
    protected function getBaseColumns(Content $record)
    {
        $contenttype = $record->contenttype;
        $baseColumns = Content::getBaseColumns();

        // Check for contenttype specific base columns setting.
        if (isset($this->config['contenttypes'][$contenttype['slug']])) {
            $config = $this->config['contenttypes'][$contenttype['slug']];

            if (isset($config['base_columns'])) {
                // Value for base columns can be an array of fields.
                if (is_array($config['base_columns'])) {
                    return $config['base_columns'];
                } else {
                    if ($config['base_columns']) {
                        return $baseColumns;
                    }
                }
            }
            // Check for a global base columns setting.
        } else {
            if (isset($this->config['base_columns'])) {
                // Value for base columns can be an array of fields.
                if (is_array($this->config['base_columns'])) {
                    return $this->config['base_columns'];
                } else {
                    if ($this->config['base_columns']) {
                        return $baseColumns;
                    }
                }
            }
        }

        return array();
    }


    /**
     * Returns the table name with the configured prefix.
     *
     * @param  string $table Table to return with prefix.
     * @return string
     */
    protected function getTableName($table)
    {
        return $this->app['config']->get('general/database/prefix', 'bolt_') . $table;
    }


    /**
     * Returns the parameters from a request.
     *
     * @param  Request $request Request to get parameters for.
     * @return array
     */
    protected function getParametersFromRequest(Request $request)
    {
        return array(
          'limit'  => intval($request->get('limit', $this->config['defaults']['limit'])),
          'order'  => $request->get('order', $request->get('orderby', $this->config['defaults']['order'])),
          'paging' => 1,
          'page'   => intval($request->get('page', 1))
        );
    }


    /**
     * Returns the where parameters from a request.
     *
     * @param  Request $request Request to get where parameters for.
     * @return array
     */
    protected function getWhereFromRequest(Request $request)
    {
        $where = $request->get('where', array());

        // Where does not work out of the box for fields that are of type 'select',
        // related to another contenttype. Values get stored as a JSON array, so we
        // perform a like query on the value between double quotes.
        $select = $request->get('select', array());

        foreach ($select as $name => $value) {
            $where[$name] = '%"' . $value . '"%';
        }

        return $where;
    }


    /**
     * Returns the query for getting a list of taxonomy values.
     *
     * @param  strig $order Field to be used for sorting.
     * @return string
     */
    protected function getTaxonomyQuery($order)
    {
        return "
      SELECT
        # Make sure we only select unique taxonomy values
        DISTINCT(T.name),
        T.slug,
        # Get the content count for each taxonomy value.
        (SELECT COUNT(TC.id) FROM " . $this->getTableName('taxonomy') . " TC WHERE TC.taxonomytype = ? AND TC.slug = T.slug ) AS results
      FROM " . $this->getTableName('taxonomy') . " T
      WHERE T.taxonomytype = ?
      ORDER BY " . $order . "
    ";
    }
}
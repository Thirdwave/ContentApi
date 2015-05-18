<?php namespace Bolt\Extension\Thirdwave\ContentApi;

use Bolt\Application;
use Bolt\BaseExtension;
use Bolt\Content;
use Bolt\Extension\Thirdwave\ContentApi\Exception\ForbiddenException;
use Bolt\Extension\Thirdwave\ContentApi\Exception\NotFoundException;
use Bolt\Library;
use Bolt\Permissions;
use Doctrine\DBAL\DBALException;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;


/**
 * ContentApi extension for Bolt
 *
 * Copyright (C) 2015  Thirdwave B.V.
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
 * @copyright Copyright (c) 2015, Thirdwave
 * @license   http://opensource.org/licenses/GPL-3.0 GNU Public License 3.0
 */
class Extension extends BaseExtension
{


    /**
     * @var array
     */
    public $config;


    /**
     * Quick access to all configured contenttype from Bolt.
     *
     * @var array
     */
    protected $contenttypes;


    /**
     * Returns the name of the extension.
     *
     * @return string
     */
    public function getName()
    {
        return "contentapi";
    }


    /**
     * Returns the version of the API.
     *
     * @return string
     */
    public function getVersion()
    {
        return "1.1.7";
    }


    /**
     * Initialize the extension.
     *
     * @todo Add API role allow for permission based on API role.
     */
    public function initialize()
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
        $routes->get('/{contenttype}', array($this, 'listing'));

        // Stores a contenttype item.
        $routes->post('/{contenttype}', array($this, 'store'));

        // Returns the latest records for a contenttype.
        $routes->match('/{contenttype}/latest/{amount}', array($this, 'listingLatest'))
          ->assert('amount', '[\d]+');

        // Returns the first records for a contenttype.
        $routes->match('/{contenttype}/first/{amount}', array($this, 'listingFirst'))
          ->assert('amount', '[\d]+');

        // Returns search results for a contenttype.
        $routes->match('/{contenttype}/search', array($this, 'searchContenttype'));

        // Returns field definition for a contenttype.
        $routes->match('/{contenttype}/fields', array($this, 'fields'));

        // Returns telephone book like filters with count.
        $routes->match('/{contenttype}/{field}/abc', array($this, 'abc'));

        // Returns a number of search results for a contenttype.
        $routes->match('/{contenttype}/search/{amount}', array($this, 'searchContenttypeAmount'))
          ->assert('amount', '[\d]+');

        // Returns a single record from a contenttype by slug or id.
        $routes->match('/{contenttype}/{slugOrId}', array($this, 'record'));

        // Include the major version in the api mounting point.
        $parts   = explode('.', $this->getVersion());
        $version = array_shift($parts);

        $this->app->mount($this->config['mounting_point'] . '/v' . $version, $routes);
    }


    /**
     * Executed before each api call. Checks for whitelist access.
     *
     * @param  Request     $request Current request.
     * @param  Application $app
     * @return null|JsonResponse
     * @throws ForbiddenException
     */
    public function before(Request $request, Application $app)
    {
        $app->on(KernelEvents::VIEW, array($this, 'createResponse'), 128);
        $app->on(KernelEvents::EXCEPTION, array($this, 'handleException'), 128);

        $client = $request->getClientIp();

        // if false, all ips are ok
        if ($this->config['whitelist'] === false) {
            return null;
        }

        // By default the ip of the server running the api is whitelisted. Other
        // ip addresses need to be configured te gain access.
        $whitelist   = $this->config['whitelist'] ?: array();
        $whitelist[] = $request->server->get('SERVER_ADDR');

        foreach ($whitelist as $ip) {
            if (strpos($client, $ip) !== false) {
                return null;
            }
        }

        throw new ForbiddenException('Access from IP ' . $this->app['request']->getClientIp() . ' is not allowed.');
    }


    /**
     * Simple response to check if the api is working.
     *
     * @return JsonResponse
     */
    public function index()
    {
        return $this->app->json(array('it' => 'works', $this->getName() => $this->getVersion()), 200, array(
          'Access-Control-Allow-Origin' => '*'
        ));
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
            return $this->app->json(array('status' => 404), 404, array(
              'Access-Control-Allow-Origin' => '*'
            ));
        }

        // Default ordering is by name.
        $order = $request->query->get('order', $request->get('orderby', 'name'));

        // Translate the order to the correct query order statement.
        switch ($order) {
            case 'name':
                $order = 'name';
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
                ), 500, array(
                  'Access-Control-Allow-Origin' => '*'
                ));
        }

        try {
            $values = $this->app['db']->executeQuery($this->getTaxonomyQuery($order),
              array($taxonomytype))->fetchAll();
        } catch ( DBALException $e ) {
            return $this->app->json(array('status' => 500, 'error' => $e->getMessage()), 500, array(
              'Access-Control-Allow-Origin' => '*'
            ));
        }

        return $this->app->json(array(
          'type'   => $taxonomytype,
          'count'  => count($values),
          'values' => $values
        ), 200, array(
          'Access-Control-Allow-Origin' => '*'
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
            return $this->app->json(array('status' => 404), 404, array(
              'Access-Control-Allow-Origin' => '*'
            ));
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

        // Allow results from multiple or all contenttypes. Note that this does
        // not work with RANDOM sorting and gives false results when using a where
        // parameter on fields that do not exist in ALL requested contenttypes.
        if ($contenttype === 'listing') {
            $types       = $request->get('contenttypes', implode(',', array_keys($this->contenttypes)));
            $query       = '(' . $types . ')';
            $contenttype = null;
        } else {
            $query = $contenttype;
        }

        if (!empty($parameters['order']) && $parameters['order'] === 'RANDOM') {
            $query = $query . '/random/' . $parameters['limit'];
        }

        return $this->listingResponse($query, $parameters, $where, $contenttype, $type);
    }


    /**
     * Returns telephone book like filters with count.
     *
     * @param  string      $contenttype
     * @param  string      $field
     * @param  Application $app
     * @return JsonResponse
     */
    public function abc($contenttype, $field, Application $app)
    {
        $this->validateContenttype($contenttype);

        $abc = array('#' => 0);

        foreach (range('A', 'Z') as $letter) {
            $abc[$letter] = 0;
        }

        $tableName = $this->getTableName($contenttype);
        $sql       = "
            SELECT
                SUBSTR({$field}, 1, 1) AS letter,
                COUNT(SUBSTR({$field}, 1, 1)) AS rows
            FROM {$tableName}
            WHERE status = 'published'
            GROUP BY letter
            ORDER BY {$field} ASC
        ";

        $result = $app['db']->executeQuery($sql)->fetchAll();

        foreach ($result as $row) {
            if (empty($row['letter'])) {
                $abc['#'] = intval($row['rows']);
            } else {
                $abc[strtoupper($row['letter'])] = intval($row['rows']);
            }
        }

        return $this->app->json($abc, 200, array(
          'Access-Control-Allow-Origin' => '*'
        ));
    }


    /**
     * Returns field definition for a contenttype.
     *
     * @param  string $contenttype
     * @return JsonResponse
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function fields($contenttype)
    {
        $this->validateContenttype($contenttype);

        $contenttype = $this->app['storage']->getContenttype($contenttype);

        return $this->app->json($contenttype['fields'], 200, array(
          'Access-Control-Allow-Origin' => '*'
        ));
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
        $this->validateContenttype($contenttype);

        // Allow for custom set of return values.
        $type   = $request->get('type', 'record');
        $expand = $request->get('expand');

        // Check if the contenttype is defined.
        if (!$this->app['storage']->getContenttype($contenttype)) {
            return $this->app->json(array('status' => 404), 404, array(
              'Access-Control-Allow-Origin' => '*'
            ));
        }

        $record = $this->app['storage']->getContent($contenttype . '/' . $slugOrId);

        if (!$record) {
            return $this->app->json(array('status' => 404), 404, array(
              'Access-Control-Allow-Origin' => '*'
            ));
        }

        return $this->app->json($this->parseRecord($record, $type, $expand), 200, array(
          'Access-Control-Allow-Origin' => '*'
        ));
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
            return $this->app->json(array('status' => 500, 'error' => 'Missing filter.'), 500, array(
              'Access-Control-Allow-Origin' => '*'
            ));
        }

        // Create a list of the requested contenttypes or list all contenttypes.
        $types = $request->get('contenttypes', implode(',', array_keys($this->contenttypes)));

        $query      = '(' . $types . ')';
        $parameters = $this->getParametersFromRequest($request);
        $where      = $this->getWhereFromRequest($request);

        // Set the search term and contenttypes.
        $parameters['filter'] = $filter;

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
     * Store content for a contenttype.
     *
     * @param string      $contenttype
     * @param Request     $request
     * @param Application $app
     * @return JsonResponse
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function store($contenttype, Request $request, Application $app)
    {
        $app['users']->login(
          $this->config['user']['username'],
          $this->config['user']['password']
        );

        $this->validateContenttype($contenttype, 'create');

        $content = $app['storage']->getContentObject($contenttype, $request->request->all());

        $app['storage']->saveContent($content);

        return $this->app->json($this->parseRecord($content, 'record'), 200, array(
          'Access-Control-Allow-Origin' => '*'
        ));
    }


    /**
     * Returns search results.
     *
     * @param      array $parameters Parameters for getting content.
     * @param      array $where      Extra where parameters.
     * @param      array $paging     Array passed by reference.
     * @return     array
     * @deprecated since 1.0.10
     */
    protected function getSearchResults(array $parameters, array $where, array &$paging)
    {
        $offset       = ($parameters['page'] - 1) * $parameters['limit'];
        $contenttypes = $parameters['contenttypes'];

        //$query = '(' . implode(',', $parameters['contenttypes']) . ',nieuws)';

        unset($parameters['contenttypes']);

        try {
            $result = $this->app['storage']->searchContent($parameters['filter'], $contenttypes, $where,
              $parameters['limit'], $offset);
        } catch ( DBALException $e ) {
            return $this->app->json(array('status' => 500, 'error' => $e->getMessage()), 500, array(
              'Access-Control-Allow-Origin' => '*'
            ));
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
     * @todo   Exclude contenttypes that were configured and check for permissions.
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
     * @param  string $type        Type of list to return.
     * @return JsonResponse
     */
    protected function listingResponse(
      $query,
      array $parameters = array(),
      array $where = array(),
      $contenttype = null,
      $type = 'listing'
    ) {
        if (!empty($contenttype)) {
            $this->validateContenttype($contenttype);
        }

        $parameters    = array_merge($parameters, $where);
        $paging        = array();
        $random        = false;
        $default_order = false;

        // Allow random search results.
        if ($type === 'search' && !empty($parameters['order']) && $parameters['order'] === 'RANDOM') {
            unset($parameters['order']);
            $random = true;
        }

        // Check if default order was given. This is used to sort content that has grouping
        // taxonomy.
        if (!empty($parameters['default_order'])) {
            unset($parameters['default_order']);
            $default_order = true;
        }

        if ($type === 'taxonomy') {
            $records = $this->getTaxonomyContent($parameters, $paging);
        } else {
            try {
                $records = $this->app['storage']->getContent($query, $parameters, $paging);
            } catch ( DBALException $e ) {
                return $this->app->json(array('status' => 500, 'error' => $e->getMessage()), 500, array(
                  'Access-Control-Allow-Origin' => '*'
                ));
            }
        }

        $records = array_values($records ?: array());

        // Sort grouping content when no specific order was given.
        // Sort grouping content when no specific order was given.
        if ( $default_order && count($records) > 0 ) {
            usort($records, function($a, $b) {
                if ( !empty($a->group) && empty($b->group) ) {
                    return -1;
                } else if ( empty($a->group) && !empty($b->group) ) {
                    return -1;
                } else if ( !empty($a->group) && !empty($b->group) ) {
                    if ( $a->group['slug'] !== $b->group['slug'] ) {
                        return strcmp($a->group['slug'], $b->group['slug']);
                    } else {
                        return ($a->group['order'] < $b->group['order']) ? -1 : 1;
                    }
                } else {
                    return 0;
                }
            });
        }

        foreach ($records as &$record) {
            $record = $this->parseRecord($record, $type, $parameters['expand']);
        }

        if ($random) {
            shuffle($records);
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
        ), 200, array(
          'Access-Control-Allow-Origin' => '*'
        ));
    }


    /**
     * Parse a record into an array of values.
     *
     * @param  Content $record
     * @param  string  $type
     * @param  array   $expand
     * @return array
     */
    protected function parseRecord(Content $record, $type = 'listing', $expand = null)
    {
        $columns = $this->getColumns($record, $type);
        $values  = array();

        foreach ($columns as $name => $properties) {
            if (is_numeric($name)) {
                $name       = $properties;
                $properties = array('type' => 'unknown');
            }

            if ($name === 'contenttype') {
                foreach ($this->contenttypes as $key => $contenttype) {
                    if ($record->contenttype['slug'] === $contenttype['slug']) {
                        $values['contenttype'] = $key;
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

        if (!empty($record->relation)) {
            if (!empty($expand)) {
                $expand = explode(',', $expand);

                foreach ($record->relation as $contenttype => $items) {
                    try {
                        $this->validateContenttype($contenttype);
                    } catch ( Exception $e ) {
                        continue;
                    }

                    if (in_array($contenttype, $expand)) {
                        $related = array();

                        foreach ($items as $id) {
                            $item = $this->app['storage']->getContent($contenttype . '/' . $id,
                              array('status' => 'published'));

                            if ($item) {
                                $related[] = $this->parseRecord($item, $type);
                            }
                        }

                        $values['relations'][$contenttype] = $related;
                    }
                }
            }
        }

        return $values;
    }


    /**
     * Parse a record value. Returns the parsed value.
     *
     * @param  $type
     * @param  $value
     * @return array|string
     */
    protected function parseRecordValue($type, $value)
    {
        if (method_exists($this, 'parseRecordValue' . ucfirst($type))) {
            $method = 'parseRecordValue' . ucfirst($type);
            return $this->$method($value);
        }

        return $value;
    }


    /**
     * Parse file value.
     *
     * @param  array|string $value
     * @return array
     */
    protected function parseRecordValueFile($value)
    {
        if (!is_array($value)) {
            $value = array(
              'file' => $value
            );
        }

        $key = 'file';

        if (empty($value[$key]) && !empty($value['filename'])) {
            $key = 'filename';
        }

        if (empty($value[$key])) {
            return $value;
        }

        $parts = explode('/', $value[$key]);
        $path  = substr($this->app['paths']['files'], 1) . $value[$key];
        $host  = $this->app['paths']['hosturl'] . '/';
        $mime  = null;
        $size  = null;

        if (file_exists($this->app['paths']['filespath'] . '/' . $value[$key])) {
            $finfo = new \finfo(FILEINFO_MIME);
            $mime  = explode(';', $finfo->file($this->app['paths']['filespath'] . '/' . $value[$key]));
            $mime  = $mime[0];
            $size  = filesize($this->app['paths']['filespath'] . '/' . $value[$key]);
        }

        return array(
          'title'     => isset($value['title']) ? $value['title'] : $value[$key],
          'file'      => $value[$key],
          'filename'  => array_pop($parts),
          'path'      => $path,
          'host'      => $host,
          'url'       => $host . $path,
          'size'      => $size,
          'extension' => Library::getExtension($value[$key]),
          'mime'      => $mime
        );
    }


    /**
     * Parse image value.
     *
     * @param  array $value
     * @return array
     */
    protected function parseRecordValueImage($value)
    {
        return $this->parseRecordValueFile($value);
    }


    /**
     * Parse image list value.
     *
     * @param  array $value
     * @return array
     */
    protected function parseRecordValueImagelist($value)
    {
        if (empty($value)) {
            return $value;
        }

        $images = array();

        foreach ($value as $image) {
            $images[] = $this->parseRecordValueImage($image);
        }

        return $images;
    }


    /**
     * Parse file list value.
     *
     * @param  array $value
     * @return array
     */
    protected function parseRecordValueFilelist($value)
    {
        return $this->parseRecordValueImagelist($value);
    }


    /**
     * Parse video value.
     *
     * @param  array $value
     * @return mixed
     */
    protected function parseRecordValueVideo($value)
    {
        if (!is_array($value) || empty($value['url'])) {
            return $value;
        }

        $youtubeRegEx = '%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i';

        if (preg_match($youtubeRegEx, $value['url'], $match)) {
            $value['id'] = $match[1];
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

            // Type is configured with custom fields.
            if (isset($config[$type])) {
                foreach ($config[$type] as $column) {
                    // Check for mapping with contenttype field.
                    if (isset($contenttype['fields'][$column])) {
                        $columns[$column] = $contenttype['fields'][$column];
                    } else {
                        $columns[] = $column;
                    }
                }
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

        if (isset($this->config['contenttypes'][$contenttype['slug']])) {
            // Check for contenttype specific base columns setting.
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
            } else {
                return $this->getDefaultBaseColumns();
            }
        } else {
            return $this->getDefaultBaseColumns();
        }

        return array();
    }


    /**
     * Returns the default configured base columns.
     *
     * @return array
     */
    public function getDefaultBaseColumns()
    {
        $baseColumns = Content::getBaseColumns();

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

        return $baseColumns;
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
        $parameters = array(
          'limit'  => intval($request->get('limit', $this->config['defaults']['limit'])),
          'order'  => $request->get('order', $request->get('orderby')),
          'paging' => true,
          'page'   => intval($request->get('page', 1)),
          'expand' => $request->get('expand')
        );

        if ( empty($parameters['order']) ) {
            $parameters['default_order'] = true;
            $parameters['order'] = $this->config['defaults']['order'];
        }

        return $parameters;
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
     * @param  string $order Field to be used for sorting.
     * @return string
     */
    protected function getTaxonomyQuery($order)
    {
        $tableName = $this->getTableName('taxonomy');
        $sql       = "
            SELECT
                DISTINCT(name),
                slug,
                COUNT(name) AS results
            FROM {$tableName}
            WHERE taxonomytype = ?
            GROUP BY name
            ORDER BY {$order}
        ";
        return $sql;
    }


    /**
     * @param  string $contenttype Name of the contenttype to validate.
     * @param  string $permisson   Type of permission to check.
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    protected function validateContenttype($contenttype, $permisson = 'view')
    {
        // Check if a given contenttype exists.
        if (!$this->app['storage']->getContenttype($contenttype)) {
            throw new NotFoundException('Contenttype ' . $contenttype . ' is not defined.');
        }

        // Check if given contenttype is configured to be excluded.
        if (in_array($contenttype, $this->config['exclude'])) {
            throw new ForbiddenException('Contenttype ' . $contenttype . ' is forbidden.');
        }

        // Check for permissions.
        $user  = $this->app['users']->getCurrentUser();
        $roles = $user ? $user['roles'] : array(Permissions::ROLE_ANONYMOUS);

        if (!$this->app['permissions']->checkPermission($roles, $permisson, $contenttype)) {
            throw new ForbiddenException('No access for permission ' . $permisson . ' to contenttype ' . $contenttype . '.');
        }
    }


    /**
     * Create response when controller does not return a response.
     *
     * @param GetResponseForControllerResultEvent $e
     */
    public function createResponse(GetResponseForControllerResultEvent $e)
    {
        $result = $e->getControllerResult();

        $response = new Response($result);

        $e->setResponse($response);
    }


    public function handleException(GetResponseForExceptionEvent $e)
    {
        $response = new Response();

        $response->setException($e->getException());
        $response->setContent($response);

        $e->setResponse($response);
    }
}
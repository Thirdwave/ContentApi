<?php namespace Bolt\Extension\Thirdwave\ContentApi;

use Exception;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as BaseResponse;


/**
 * API Response
 *
 * @author  G.P. Gautier <ggautier@thirdwave.nl>
 * @version 0.8.0, 2014/12/24
 */
class Response extends BaseResponse {


  /**
   * Status successful.
   */
  const STATUS_SUCCESSFUL = 'successful';


  /**
   * Client error. Errors with status code in 400 range.
   */
  const STATUS_CLIENTERROR = 'clienterror';


  /**
   * Server error. Errors with status code in 500 range.
   */
  const STATUS_SERVERERROR = 'servererror';


  /**
   * Properties of the response. Will be sent as data.
   *
   * @var array
   */
  protected $properties = array();


  /**
   * Create a new response.
   *
   * @param array $data
   * @param int   $statusCode
   */
  public function __construct(array $data = array(), $statusCode = 200) {
    $this->properties['data'] = $data;

    parent::__construct(null, $statusCode);
  }


  /**
   * Set exception for the response.
   *
   * @param Exception $e
   */
  public function setException(Exception $e) {
    $parts = explode('\\', get_class($e));

    $this->properties['error'] = array(
      'type'    => array_pop($parts),
      'message' => $e->getMessage(),
      'code'    => $e->getCode()
    );

    try {
      $this->setStatusCode($e->getCode());
    } catch ( InvalidArgumentException $exception ) {
      $this->setStatusCode(500);
    }
  }


  /**
   * Returns a value from the properties array.
   *
   * @param  string $key Key from properties array.
   * @return mixed
   * @throws InvalidArgumentException When key is not found in properties.
   */
  public function __get($key) {
    if (!isset($this->properties[$key])) {
      throw new InvalidArgumentException('Cannot get value for unknown property ' . $key . '.');
    }

    return $this->properties[$key];
  }


  /**
   * Set a value for a property.
   *
   * @param  string $key   Property key to set.
   * @param  mixed  $value Property value.
   */
  public function __set($key, $value) {
    $this->properties[$key] = $value;
  }


  /**
   * Returns the response as JSON string.
   *
   * @return string
   */
  public function __toString() {
    $code = $this->getStatusCode();

    $this->properties['code'] = $code;

    if ($code === 200) {
      $this->properties['status'] = self::STATUS_SUCCESSFUL;
    } else {
      if ($code >= 400 && $code < 500) {
        $this->properties['status'] = self::STATUS_CLIENTERROR;
      } else {
        $this->properties['status'] = self::STATUS_SERVERERROR;
      }
    }

    return json_encode($this->properties);
  }
} 
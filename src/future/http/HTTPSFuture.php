<?php

/**
 * Very basic HTTPS future.
 *
 * TODO: This class is extremely limited.
 *
 * @group futures
 */
final class HTTPSFuture extends BaseHTTPFuture {

  private static $handle;

  private $profilerCallID;

  private $cabundle;

  /**
   * Create a temp file containing an SSL cert, and use it for this session.
   *
   * This allows us to do host-specific SSL certificates in whatever client
   * is using libphutil. e.g. in Arcanist, you could add an "ssl_cert" key
   * to a specific host in ~/.arcrc and use that.
   *
   * cURL needs this to be a file, it doesn't seem to be able to handle a string
   * which contains the cert. So we make a temporary file and store it there.
   *
   * @param string The multi-line, possibly lengthy, SSL certificate to use.
   * @return this
   */
  public function setCABundleFromString($certificate) {
    $temp = new TempFile();
    Filesystem::writeFile($temp, $certificate);
    $this->cabundle = $temp;
    return $this;
  }

  /**
   * Set the SSL certificate to use for this session, given a path.
   *
   * @param string The path to a valid SSL certificate for this session
   * @return this
   */
  public function setCABundleFromPath($path) {
    $this->cabundle = $path;
    return $this;
  }

  /**
   * Get the path to the SSL certificate for this session.
   *
   * @return string|null
   */
  public function getCABundle() {
    return $this->cabundle;
  }

  /**
   * Load contents of remote URI. Behaves pretty much like
   *  `@file_get_contents($uri)` but doesn't require `allow_url_fopen`.
   *
   * @param string
   * @param float
   * @return string|false
   */
  public static function loadContent($uri, $timeout = null) {
    $future = new HTTPSFuture($uri);
    if ($timeout !== null) {
      $future->setTimeout($timeout);
    }
    try {
      list($body) = $future->resolvex();
      return $body;
    } catch (HTTPFutureResponseStatus $ex) {
      return false;
    }
  }

  public function isReady() {
    if (isset($this->result)) {
      return true;
    }

    $uri = $this->getURI();
    $data = $this->getData();

    if ($data) {

      // NOTE: PHP's cURL implementation has a piece of magic which treats
      // parameters as file paths if they begin with '@'. This means that
      // an array like "array('name' => '@/usr/local/secret')" will attempt to
      // read that file off disk and send it to the remote server. This behavior
      // is pretty surprising, and it can easily become a relatively severe
      // security vulnerability which allows an attacker to read any file the
      // HTTP process has access to. Since this feature is very dangerous and
      // not particularly useful, we prevent its use.
      //
      // After PHP 5.2.0, it is sufficient to pass a string to avoid this
      // "feature" (it is only invoked in the array version). Prior to
      // PHP 5.2.0, we block any request which have string data beginning with
      // '@' (they would not work anyway).

      if (is_array($data)) {
        // Explicitly build a query string to prevent "@" security problems.
        $data = http_build_query($data, '', '&');
      }

      if ($data[0] == '@' && version_compare(phpversion(), '5.2.0', '<')) {
        throw new Exception(
          "Attempting to make an HTTP request including string data that ".
          "begins with '@'. Prior to PHP 5.2.0, this reads files off disk, ".
          "which creates a wide attack window for security vulnerabilities. ".
          "Upgrade PHP or avoid making cURL requests which begin with '@'.");
      }
    } else {
      $data = null;
    }

    $profiler = PhutilServiceProfiler::getInstance();
    $this->profilerCallID = $profiler->beginServiceCall(
      array(
        'type' => 'http',
        'uri' => $uri,
      ));

    // NOTE: If possible, we reuse the handle so we can take advantage of
    // keepalives. This means every option has to be set every time, because
    // cURL will not clear the settings between requests.

    if (!self::$handle) {
      self::$handle = curl_init();
    }
    $curl = self::$handle;

    curl_setopt($curl, CURLOPT_URL, $uri);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

    $headers = $this->getHeaders();
    if ($headers) {
      for ($ii = 0; $ii < count($headers); $ii++) {
        list($name, $value) = $headers[$ii];
        $headers[$ii] = $name.': '.$value;
      }
      curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    } else {
      curl_setopt($curl, CURLOPT_HTTPHEADER, array());
    }

    // Set the requested HTTP method, e.g. GET / POST / PUT.
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->getMethod());

    // Make sure we get the headers and data back.
    curl_setopt($curl, CURLOPT_HEADER, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_MAXREDIRS, 20);

    if (defined('CURLOPT_TIMEOUT_MS')) {
      // If CURLOPT_TIMEOUT_MS is available, use the higher-precision timeout.
      $timeout = max(1, ceil(1000 * $this->getTimeout()));
      curl_setopt($curl, CURLOPT_TIMEOUT_MS, $timeout);
    } else {
      // Otherwise, fall back to the lower-precision timeout.
      $timeout = max(1, ceil($this->getTimeout()));
      curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
    }

    // Try some decent fallbacks here:
    // - First, check if a cabundle path is already set (e.g. externally).
    // - Then, if a local custom.pem exists, use that, because it probably means
    //   that the user wants to override everything (also because the user might
    //   not have access to change the box's php.ini to add curl.cainfo.
    // - Otherwise, try using curl.cainfo. If it's set explicitly, it's probably
    //   reasonable to try using it before we fall back to what libphutil
    //   ships with.
    // - Lastly, try the default that libphutil ships with. If it doesn't
    //   work, give up and yell at the user.
    if (!$this->getCABundle()) {
      $caroot = dirname(phutil_get_library_root('phutil')).'/resources/ssl/';
      $ini_val = ini_get('curl.cainfo');
      if (Filesystem::pathExists($caroot.'custom.pem')) {
        $this->setCABundleFromPath($caroot.'custom.pem');
      } else if ($ini_val) {
        // TODO: We can probably do a pathExists() here, even.
        $this->setCABundleFromPath($ini_val);
      } else {
        $this->setCABundleFromPath($caroot.'default.pem');
      }
    }

    curl_setopt($curl, CURLOPT_CAINFO, $this->getCABundle());
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_SSLVERSION, 0);

    $result = curl_exec($curl);
    $err_code = curl_errno($curl);

    if ($err_code) {
      $status = new HTTPFutureResponseStatusCURL($err_code, $uri);
      $body = null;
      $headers = array();
      $this->result = array($status, $body, $headers);
    } else {
      // cURL returns headers of all redirects, we strip all but the final one.
      $redirects = curl_getinfo($curl, CURLINFO_REDIRECT_COUNT);
      $result = preg_replace('/^(.*\r\n\r\n){'.$redirects.'}/sU', '', $result);
      $this->result = $this->parseRawHTTPResponse($result);
    }

    // NOTE: Don't call curl_close(), we want to use keepalive if possible.

    $profiler = PhutilServiceProfiler::getInstance();
    $profiler->endServiceCall($this->profilerCallID, array());

    return true;
  }
}

<?php
namespace Wangjian\Socket;

Trait HttpTrait {
    /**
     * allowed http methods
     * @var array
     */
    protected $methods = ['GET', 'POST', 'HEAD', 'OPTIONS'];

    /**
     * MIME types
     * @var array
     */
    protected $mimes = [
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'dtd' => 'application/xml-dtd',
        'xhtml' => 'application/xhtml+xml',
        'bmp' => 'application/x-bmp',
        'html' => 'text/html',
        'php' => 'text/html',
        'htm' => 'text/html',
        'img' => 'application/x-img',
        'jpe' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff'
    ];

    /**
     * the virtual host configuration
     * @var array
     */
    public $hosts = array();

    /**
     * add Mime types
     * @param array|string $ext
     * @param string $value
     */
    public function addMimeTypes($ext, $value = '') {
        if(is_array($ext)) {
            $this->mimes = array_merge($this->mimes, $ext);
        } else {
            $this->mimes[$ext] = $value;
        }
    }


    /**
     * get the Mime type
     * @param string $ext  the extension
     * @return string
     */
    public function getMimeType($ext) {
        if(empty($this->mimes[$ext])) {
            return 'application/octet-stream';
        }

        return $this->mimes[$ext];
    }

    /**
     * get the host configuration
     * @param string $host  the server name
     * @return array
     */
    public function getHostConfig($host) {
        if(empty($host)) {
            return isset($this->hosts['default']) ? $this->hosts['default'] : [];
        }

        return isset($this->hosts[$host]) ? $this->hosts[$host] : (isset($this->hosts['default']) ? $this->hosts['default'] : []);
    }

    /**
     * get the allowed http methods
     * @return array
     */
    public function allowedMethods() {
        return $this->methods;
    }
}
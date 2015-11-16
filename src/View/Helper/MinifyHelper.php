<?php

namespace Borg\View\Helper;

use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\View\Helper;
use Cake\View\View;
use Cake\Utility\Inflector;

/**
 * Minify
 * Will minify HTML
 * Will combine and minify JS and CSS files
 *
 * Load:
 * ---------------------------------------------------
 * This helper must be loaded in your View/AppView.php
 * before you can use it
 *
 * $this->loadHelper('Borg.Minify', [
 *     'html' => [
 *         'compression' => true
 *     ], 'css' => [
 *         'path' => '/cache-css', // without trailing slash
 *         'compression' => true
 *     ], 'js' => [
 *         'path' => '/cache-js', // without trailing slash
 *         'compression' => true,
 *         'async' => true
 *     ]
 * ]);
 *
 * Usage:
 * ---------------------------------------------------
 * $this->Minify->style('your-css-file'); // or array
 * $this->Minify->fetch('style');
 *
 * $this->Minify->script('your-script'); // or array
 * $this->Minify->fetch('script');
 *
 * Simulate live in development:
 * ---------------------------------------------------
 * $this->Minify->fetch('style', true);
 *
 * @author Borg
 * @version 0.7
 */
class MinifyHelper extends Helper {
    // load html and url helpers
    public $helpers = ['Html', 'Url'];

    // default conf
    protected $_defaultConfig = [
        'html' => [
            'compression' => true
        ], 'css' => [
            'path' => '/cache-css', // without trailing slash
            'compression' => true
        ], 'js' => [
            'path' => '/cache-js', // without trailing slash
            'compression' => true,
            'async' => true
        ]
    ];

    // container for css and js files
    private $css = ['intern' => [], 'extern' => []];
    private $js = ['intern' => [], 'extern' => []];

    // simulate live
    private $live = false;

    /**
     * Constructor
     * @param View $View
     * @param unknown $settings
     */
    public function __construct(View $View, array $config = []) {
        // call parent constructor
        parent::__construct($View, $config);

        // calculate file system route
        $this->_config['css']['route'] = rtrim(WWW_ROOT, DS) . str_replace('/', DS, $this->_config['css']['path']);
        $this->_config['js']['route'] = rtrim(WWW_ROOT, DS) . str_replace('/', DS, $this->_config['js']['path']);
    }

    /**
     * HTML compressor
     * @see Helper::afterLayout()
     */
     public function afterLayout() {
        $this->_View->Blocks->set('content', $this->_html($this->_View->Blocks->get('content')));
     }

    /**
     * Add css files to list
     * @param array $files
     */
    public function style($files = null) {
        // nothing?
        if(is_null($files))
            return;

        // string? convert to array
        if(is_string($files))
            $files = [$files];

        // not array?
        if(!is_array($files))
            return;

        // add each file to group with www_root
        $group = [];
        foreach($files as $url)
            $group[] = $this->path($url, ['pathPrefix' => Configure::read('App.cssBaseUrl'), 'ext' => '.css']);

        // array merge
        $this->css['intern'] = array_merge($group, $this->css['intern']);
        $this->css['extern'] = array_merge($files, $this->css['extern']);
    }

    /**
     * Add js files to list
     * @param array $files
     */
    public function script($files = null) {
        // nothing?
        if(is_null($files))
            return;

        // string? convert to array
        if(is_string($files))
            $files = [$files];

        // not array?
        if(!is_array($files))
            return;

        // add each file to group with www_root
        $group = [];
        foreach($files as $url)
            $group[] = $this->path($url, ['pathPrefix' => Configure::read('App.jsBaseUrl'), 'ext' => '.js']);

        // array merge
        $this->js['intern'] = array_merge($group, $this->js['intern']);
        $this->js['extern'] = array_merge($files, $this->js['extern']);
    }

    /**
     * Fetch either combined css or js
     * @param string $what style | script
     * @throws CakeException
     */
    public function fetch($what = null, $live = false) {
        // not supported?
        if(!in_array($what, ['style', 'script']))
            throw new CakeException("{$what} not supported");

        // simulate live?
        $this->live = $live;

        // call private function
        $function = '_' . $what;
        $this->$function();
    }

    /**
     * Get full webroot path for an asset
     * @param string $path
     * @param array $options
     * @return string
     */
    private function path($path, array $options = []) {
        // get base and full paths
        $base = $this->Url->assetUrl($path, $options);
        $base = $this->Url->webroot($base);

        // do webroot path
        $filepath = preg_replace('/^' . preg_quote($this->request->webroot, '/') . '/', '', urldecode($base));
        $webrootPath = WWW_ROOT . str_replace('/', DS, $filepath);
        if(file_exists($webrootPath))
            return $webrootPath;

        // do plugin webroot path
        $segments = explode('/', ltrim($filepath, '/'));
        $plugin = Inflector::camelize($segments[0]);
        if(Plugin::loaded($plugin)) {
            unset($segments[0]);
            $pluginPath = str_replace('/', DS, Plugin::path($plugin)) . 'webroot' . DS . implode(DS, $segments);

            return $pluginPath;
        }
    }

    /**
     * Attempt to create the filename for the selected resources
     * @param string $what js | css
     * @throws CakeException
     * @return string
     */
    private function filename($what = null) {
        // not supported?
        if(!in_array($what, ['css', 'js']))
            throw new CakeException("{$what} not supported");

        $last = 0;
        $loop = $this->$what;
        foreach($loop['intern'] as $res)
            if(file_exists($res))
                $last = max($last, filemtime($res));

        return "cache-{$last}-" . md5(serialize($loop['intern'])) . ".{$what}";
    }

    /**
     * Chunk content of files into array
     * @param array $files
     * @return array
     */
    private function chunks($files = []) {
        $index = 0;
        $output[$index] = null;

        // go through each file
        foreach($files as $idx => $file) {
            $content = "\n" . file_get_contents($file) . "\n";
            if(strlen($output[$index] . $content) > 100000) {
                $index++;
                $output[$index] = null;
            }

            // concat
            $output[$index] .= $content;
        }

        // return array
        return $output;
    }

    /**
     * HTML compressor
     * @param string $content
     * @return string
     */
    private function _html($content) {
        // compress?
        if(!Configure::read('debug') && $this->_config['html']['compression'])
            $content = trim(\Minify_HTML::minify($content));

        // return
        return $content;
    }

    /**
     * Create the cache file if it doesnt exist
     * Return the combined css either compressed or not (depending on the setting)
     */
    private function _style() {
        // only compress if we're in production
        if(!Configure::read('debug') || $this->live == true) {
            // no cache file? write it
            $cache = $this->filename('css');
            if(!file_exists($this->_config['css']['route'] . DS . $cache)) {
                // get chunks
                $output = null;
                $chunks = $this->chunks($this->css['intern']);

                // replace relative paths to absolute paths
                foreach($chunks as $idx => $content)
                    $chunks[$idx] = preg_replace('/(\.\.\/)+/i', $this->Url->build($this->request->webroot, true), $content);

                // compress?
                if($this->_config['css']['compression']) {
                    $obj = new \CSSmin();
                    foreach($chunks as $content)
                        $output .= trim($obj->run($content));
                }

                // not compressed
                else $output = implode("\n", $chunks);

                // write to file
                file_put_contents($this->_config['css']['route'] . DS . $cache, $output);
            }

            // output with the HTML helper
            echo $this->Html->css($this->_config['css']['path'] . '/' . $cache);

        // development mode, output separately with the HTML helper
        } else echo $this->Html->css($this->css['extern']);
    }

    /**
     * Create the cache file if it doesnt exist
     * Return the combined js either compressed or not (depending on the setting)
     */
    private function _script() {
        // only compress if we're in production
        if(!Configure::read('debug') || $this->live == true) {
            // no cache file? write it
            $cache = $this->filename('js');
            if(!file_exists($this->_config['js']['route'] . DS . $cache)) {
                // get chunks
                $output = null;
                $chunks = $this->chunks($this->js['intern']);

                // compress?
                if($this->_config['js']['compression'])
                    foreach($chunks as $content)
                        $output .= trim(\Minify_JS_ClosureCompiler::minify($content));

                // not compressed
                else $output = implode("\n", $chunks);

                // write to file
                file_put_contents($this->_config['js']['route'] . DS . $cache, $output);
            }

            // output with the HTML helper
            echo $this->Html->script($this->_config['js']['path'] . '/' . $cache, $this->_config['js']['async'] == true ? ['async' => 'async'] : []);

        // development mode, output separately with the HTML helper
        } else echo $this->Html->script($this->js['extern']);
    }
}
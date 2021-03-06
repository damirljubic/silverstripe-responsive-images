<?php

namespace Heyday\ResponsiveImages;

use ArrayData;
use ArrayList;
use Config;
use Exception;
use Requirements;

/**
 * An extension to the Image class to inject methods for responsive image sets.
 * Image sets are defined in the config layer, e.g:
 *
 * Heyday\ResponsiveImages\ResponsiveImageExtension:
 *   sets:
 *     MyResponsiveImageSet:
 *       method: CroppedImage
 *       arguments:
 *         "(min-width: 200px)": [200, 100]
 *         "(min-width: 800px)": [200, 400]
 *         "(min-width: 1200px) and (min-device-pixel-ratio: 2.0)": [800, 400]
 *       default_arguments: [200, 400]
 *
 * This provides $MyImage.MyResponsiveImageSet to the template. For more
 * documentation on implementation, see the README file.
 */
class ResponsiveImageExtension extends \Extension
{
    /**
     * @var array
     * @config
     */
    private static $default_arguments = array(800, 600);

    /**
     * @var string
     * @config
     */
    private static $default_method = 'SetWidth';

    /**
     * @var array A cached copy of the image sets
     */
    protected $configSets;

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        parent::__construct();
        $this->configSets = Config::inst()->get(__CLASS__, 'sets') ?: array();
    }

    /**
     * A wildcard method for handling responsive sets as template functions,
     * e.g. $MyImage.ResponsiveSet1
     *
     * @param string $method The method called
     * @param array $args The arguments passed to the method
     * @return HTMLText
     */
    public function __call($method, $args)
    {
        if ($config = $this->getConfigForSet($method)) {
            return $this->createResponsiveSet($config, $args, $method);
        }
    }

    /**
     * Requires the necessary JS and sends the required HTML structure to the
     * template for a responsive image set.
     *
     * @param array $config The configuration of the responsive image set
     * @param array $defaultArgs The arguments passed to the responsive image
     *                           method call, e.g. $MyImage.ResponsiveSet(800x600)
     * @param string $set The method, or responsive image set, to generate
     * @return SSViewer
     */
    protected function createResponsiveSet($config, $defaultArgs, $set)
    {
        Requirements::javascript(RESPONSIVE_IMAGES_DIR . '/javascript/picturefill/picturefill.min.js');

        if (!isset($config['arguments']) || !is_array($config['arguments'])) {
            throw new Exception("Responsive set $set does not have any arguments defined in its config.");
        }

        if (empty($defaultArgs)) {
            if (isset($config['default_arguments'])) {
                $defaultArgs = $config['default_arguments'];
            } else {
                $defaultArgs = Config::inst()->get(__CLASS__, 'default_arguments');
            }
        }

        if (isset($config['method'])) {
            $methodName = $config['method'];
        } else {
            $methodName = Config::inst()->get(__CLASS__, 'default_method');
        }

        $sizes = ArrayList::create();
        foreach ($config['arguments'] as $query => $args) {
            if (is_numeric($query) || !$query) {
                throw new Exception("Responsive set $set has an empty media query. Please check your config format");
            }

            if (!is_array($args) || empty($args)) {
                throw new Exception("Responsive set $set doesn't have any arguments provided for the query: $query");
            }

            if (\ClassInfo::exists('FocusPointImage') && $methodName == 'CroppedFocusedImage') {
                $cropData = $this->owner->calculateCrop($args[0], $args[1]);
                $args[] = $cropData['CropAxis'];
                $args[] = $cropData['CropOffset'];
            }

            array_unshift($args, $methodName);
            $image = call_user_func_array(array($this->owner, 'getFormattedImage'), $args);
            $sizes->push(ArrayData::create(array(
                'Image' => $image,
                'Query' => $query
            )));
        }

        // The first argument may be an image method such as 'CroppedImage'
        if (!isset($defaultArgs[0]) || !$this->owner->hasMethod($defaultArgs[0])) {
            array_unshift($defaultArgs, $methodName);
        }
         
        if (\ClassInfo::exists('FocusPointImage') && $methodName == 'CroppedFocusedImage') {
            if ($this->owner->hasMethod('calculateCrop')) {
                $cropData = $this->owner->calculateCrop($defaultArgs[1], $defaultArgs[2]);
                $defaultArgs[] = $cropData['CropAxis'];
                $defaultArgs[] = $cropData['CropOffset'];
            }
        }

        $image = call_user_func_array(array($this->owner, 'getFormattedImage'), $defaultArgs);
        return $this->owner->customise(array(
            'Sizes' => $sizes,
            'DefaultImage' => $image
        ))->renderWith('ResponsiveImageSet');
    }

    /**
     * Due to {@link Object::allMethodNames()} requiring methods to be expressed
     * in all lowercase, getting the config for a given method requires a
     * case-insensitive comparison.
     *
     * @param string $setName The name of the responsive image set to get
     * @return array|false
     */
    protected function getConfigForSet($setName)
    {
        $name = strtolower($setName);
        $sets = array_change_key_case($this->configSets, CASE_LOWER);

        return (isset($sets[$name])) ? $sets[$name] : false;
    }

    /**
     * Returns a list of available image sets.
     *
     * @return array
     */
    protected function getResponsiveSets()
    {
        return array_map('strtolower', array_keys($this->configSets));
    }

    /**
     * Defines all the methods that can be called in this class.
     *
     * @return array
     */
    public function allMethodNames()
    {
        return $this->getResponsiveSets();
    }
}

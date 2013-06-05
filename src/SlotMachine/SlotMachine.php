<?php

namespace SlotMachine;

use Symfony\Component\HttpFoundation\Request;

/**
 * Dynamic page content container.
 *
 * @package slotmachine
 * @author Adam Elsodaney <adam@archfizz.co.uk>
 */
class SlotMachine extends \Pimple implements \Countable
{
    /**
     * @var array
     */
    protected $config;

    /**
     * @var array
     */
    protected $reels;

    /**
     * @var Request
     */
    protected $request;

    const NOT_SET_PARAMETER = "not_set";

    /**
     * @param array         $config   The SlotMachine configuration data
     * @param Request|null  $request  The Request object
     */
    public function __construct(array $config = array(), Request $request = null)
    {
        parent::__construct();

        $machine = $this;

        $this->config = $config;
        $this->request = !is_null($request) ? $request : Request::createFromGlobals();

        $this->initialize();
    }

    /**
     * Set up the SlotMachine in a ready to use state
     */
    private function initialize()
    {
        $machine = $this;

        foreach ($this->config['slots'] as $slotName => &$slotData) {
            $slotData['name'] = $slotName;

            if (is_string($slotData['reel'])) {
                $slotData['reel'] = $this->config['reels'][$slotData['reel']];
            }

            if (!isset($slotData['nested'])) {
                $slotData['nested'] = array();
            }

            $this[$slotName] = $this->share(function ($machine) use ($slotData) {
                return new Slot($slotData);
            });
        }
    }

    /**
     * Interpolates cards values into the cards nested slot placeholders.
     * Based on the example given in the PSR-3 specification.
     *
     * @link https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md PSR-3 specification
     * @param string $card
     * @param array  $nestedCards
     * @param array  $delimiter
     * @return string
     */
    public static function interpolate($message, array $context = array(), array $delimiter = array('{', '}'))
    {
        if (2 > $tokens = count($delimiter)) {
            throw new \LengthException('Number of delimiter tokens too short. Method requires exactly 2.');
        }

        if ($tokens > 2) {
            trigger_error('Too many delimiter tokens given', E_USER_WARNING);
        }

        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
          $replace[$delimiter[0] . $key . $delimiter[1]] = $val;
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }

    /**
     * @param string  $slot
     * @param integer $default
     * @return string
     */
    public function get($slot, $default = 0)
    {
        // If no nested slots, return the card as is.
        if (0 === count($nested = $this[$slot]->getNested())) {
            return $this[$slot]->getCard($this->resolveIndex($slot, $default));
        }

        // Resolve Nested Slots
        $nestedCards = array();

        // Get the cards of the nested slots
        foreach ($nested as $nestedSlot) {
            $nestedCards[$nestedSlot] = $this[$nestedSlot]->getCard($this->resolveIndex($nestedSlot, $default));
        }

        // Translate the placeholders in the parent card.
        return static::interpolate($this[$slot]->getCard($this->resolveIndex($slot, $default)), $nestedCards);
    }

    protected function resolveIndex($slot, $default = 0)
    {
        $keyWithSetValue = false;
        $slotKeys = $this[$slot]->getKeys();

        // Perform a dry-run to find out if a value has been set, if it hasn't then assign a string.
        // The `has()` method for the Request's `query` property won't work recursively for array parameters.
        foreach ($slotKeys as $key) {
            $dry = $this->request->query->get($key, static::NOT_SET_PARAMETER, true);
            if (static::NOT_SET_PARAMETER !== $dry) {
                $keyWithSetValue = $key;
                break;
            }
        }

        // If a key was not set a value, return the default value of the first key assigned to the slot.
        return $this->request->query->getInt(($keyWithSetValue ?: $slotKeys[0]), $default, true);
    }

    /**
     * @param Request $request
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * The number of Slots in the machine
     *
     * @return integer
     */
    public function count()
    {
        // Using Pimple::$values will return the Closures, so instead get the
        // values in the container via ArrayAccess.
        foreach ($this->keys() as $valueName) {
            static $count;
            if ($this[$valueName] instanceof Slot) {
                ++$count;
            }
        }
        return $count;
    }
}

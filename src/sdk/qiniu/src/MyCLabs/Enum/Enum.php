<?php
namespace MyCLabs\Enum;

/**
 * Minimal compatible subset of myclabs/php-enum (MIT) bundled for the Qiniu
 * Kodo SDK. Qiniu only uses Enum::from() / values() / search() semantics.
 */
abstract class Enum implements \JsonSerializable
{
    protected $value;

    private static $cache = array();

    public function __construct($value)
    {
        if ($value instanceof static) {
            $value = $value->getValue();
        }
        if (!self::isValid($value)) {
            throw new \UnexpectedValueException("Value '$value' is not part of the enum " . get_called_class());
        }
        $this->value = $value;
    }

    public static function from($value)
    {
        return new static($value);
    }

    public static function toArray()
    {
        $class = get_called_class();
        if (!isset(self::$cache[$class])) {
            $reflection = new \ReflectionClass($class);
            self::$cache[$class] = $reflection->getConstants();
        }
        return self::$cache[$class];
    }

    public static function values()
    {
        $values = array();
        foreach (self::toArray() as $value) {
            $values[] = new static($value);
        }
        return $values;
    }

    public static function search($value)
    {
        return array_search($value, self::toArray(), true);
    }

    public static function isValid($value)
    {
        return in_array($value, self::toArray(), true);
    }

    public function getValue()
    {
        return $this->value;
    }

    public function equals($enum = null)
    {
        return $enum instanceof static && $this->getValue() === $enum->getValue();
    }

    public function jsonSerialize()
    {
        return $this->getValue();
    }

    public function __toString()
    {
        return (string) $this->getValue();
    }
}

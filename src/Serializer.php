<?php

/**
 * League.Csv (https://csv.thephpleague.com)
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Csv;

use Closure;
use Iterator;
use League\Csv\Serializer\CastToArray;
use League\Csv\Serializer\CastToBool;
use League\Csv\Serializer\CastToDate;
use League\Csv\Serializer\CastToEnum;
use League\Csv\Serializer\CastToFloat;
use League\Csv\Serializer\CastToInt;
use League\Csv\Serializer\CastToString;
use League\Csv\Serializer\Cell;
use League\Csv\Serializer\ClosureCasting;
use League\Csv\Serializer\MappingFailed;
use League\Csv\Serializer\PropertySetter;
use League\Csv\Serializer\Type;
use League\Csv\Serializer\TypeCasting;
use League\Csv\Serializer\TypeCastingFailed;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

use function array_key_exists;
use function array_reduce;
use function array_search;
use function array_values;
use function count;
use function in_array;
use function is_int;

final class Serializer
{
    private static bool $emptyStringAsNull = true;

    private readonly ReflectionClass $class;
    /** @var array<ReflectionProperty> */
    private readonly array $properties;
    /** @var non-empty-array<PropertySetter> */
    private readonly array $propertySetters;

    /**
     * @param class-string $className
     * @param array<string> $propertyNames
     *
     * @throws MappingFailed
     * @throws ReflectionException
     */
    public function __construct(string $className, array $propertyNames = [])
    {
        $this->class = new ReflectionClass($className);
        $this->properties = $this->class->getProperties();
        $this->propertySetters = $this->findPropertySetters($propertyNames);
    }

    public static function allowEmptyStringAsNull(): void
    {
        self::$emptyStringAsNull = true;
    }

    public static function disallowEmptyStringAsNull(): void
    {
        self::$emptyStringAsNull = false;
    }

    public static function registerType(string $type, Closure $closure): void
    {
        ClosureCasting::register($type, $closure);
    }

    public static function unregisterType(string $type): void
    {
        ClosureCasting::unregister($type);
    }

    /**
     * @param class-string $className
     * @param array<?string> $record
     *
     * @throws MappingFailed
     * @throws ReflectionException
     * @throws TypeCastingFailed
     */
    public static function assign(string $className, array $record): object
    {
        return (new self($className, array_keys($record)))->deserialize($record);
    }

    /**
     * @param class-string $className
     * @param array<string> $propertyNames
     *
     * @throws MappingFailed
     * @throws ReflectionException
     * @throws TypeCastingFailed
     */
    public static function assignAll(string $className, iterable $records, array $propertyNames = []): Iterator
    {
        return (new self($className, $propertyNames))->deserializeAll($records);
    }

    public function deserializeAll(iterable $records): Iterator
    {
        $check = true;
        $assign = function (array $record) use (&$check) {
            $object = $this->class->newInstanceWithoutConstructor();
            $this->hydrate($object, $record);

            if ($check) {
                $check = false;
                $this->assertObjectIsInValidState($object);
            }

            return $object;
        };

        return MapIterator::fromIterable($records, $assign);
    }

    /**
     * @throws ReflectionException
     * @throws TypeCastingFailed
     */
    public function deserialize(array $record): object
    {
        $object = $this->class->newInstanceWithoutConstructor();

        $this->hydrate($object, $record);
        $this->assertObjectIsInValidState($object);

        return $object;
    }

    /**
     * @param array<?string> $record
     *
     * @throws TypeCastingFailed
     */
    private function hydrate(object $object, array $record): void
    {
        $record = array_values($record);
        foreach ($this->propertySetters as $propertySetter) {
            $value = $record[$propertySetter->offset];
            if ('' === $value && self::$emptyStringAsNull) {
                $value = null;
            }

            $propertySetter($object, $value);
        }
    }

    /**
     * @throws TypeCastingFailed
     */
    private function assertObjectIsInValidState(object $object): void
    {
        foreach ($this->properties as $property) {
            if (!$property->isInitialized($object)) {
                throw new TypeCastingFailed('The property '.$this->class->getName().'::'.$property->getName().' is not initialized.');
            }
        }
    }

    /**
     * @param array<string> $propertyNames
     *
     * @throws MappingFailed
     *
     * @return non-empty-array<PropertySetter>
     */
    private function findPropertySetters(array $propertyNames): array
    {
        $propertySetters = [];
        foreach ($this->class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $attribute = $property->getAttributes(Cell::class, ReflectionAttribute::IS_INSTANCEOF);
            if ([] !== $attribute) {
                continue;
            }

            /** @var int|false $offset */
            $offset = array_search($property->getName(), $propertyNames, true);
            if (false === $offset) {
                continue;
            }

            $propertySetters[] = new PropertySetter($property, $offset, $this->resolveTypeCasting($property));
        }

        $propertySetters = [...$propertySetters, ...$this->findPropertySettersByAttribute($propertyNames)];
        if ([] === $propertySetters) {
            throw new MappingFailed('No properties or method setters were found eligible on the class `'.$this->class->getName().'` to be used for type casting.');
        }

        return $propertySetters;
    }

    /**
     * @param array<string> $propertyNames
     *
     * @return array<PropertySetter>
     */
    private function findPropertySettersByAttribute(array $propertyNames): array
    {
        $addPropertySetter = function (array $carry, ReflectionProperty|ReflectionMethod $accessor) use ($propertyNames) {
            $propertySetter = $this->findPropertySetter($accessor, $propertyNames);
            if (null === $propertySetter) {
                return $carry;
            }

            $carry[] = $propertySetter;

            return $carry;
        };

        return array_reduce(
            [...$this->properties, ...$this->class->getMethods(ReflectionMethod::IS_PUBLIC)],
            $addPropertySetter,
            []
        );
    }

    /**
     * @param array<string> $propertyNames
     *
     * @throws MappingFailed
     */
    private function findPropertySetter(ReflectionProperty|ReflectionMethod $accessor, array $propertyNames): ?PropertySetter
    {
        $attributes = $accessor->getAttributes(Cell::class, ReflectionAttribute::IS_INSTANCEOF);
        if ([] === $attributes) {
            return null;
        }

        if (1 < count($attributes)) {
            throw new MappingFailed('Using more than one `'.Cell::class.'` attribute on a class property or method is not supported.');
        }

        /** @var Cell $cell */
        $cell = $attributes[0]->newInstance();
        $offset = $cell->offset ?? match (true) {
            $accessor instanceof ReflectionMethod => $accessor->getParameters()[0]->getName(),
            default => $accessor->getName(),
        };

        $cast = $this->getTypeCasting($cell, $accessor);
        if (is_int($offset)) {
            return match (true) {
                0 > $offset => throw new MappingFailed('column integer position can only be positive or equals to 0; received `'.$offset.'`'),
                [] !== $propertyNames && $offset > count($propertyNames) - 1 => throw new MappingFailed('column integer position can not exceed property names count.'),
                default => new PropertySetter($accessor, $offset, $cast),
            };
        }

        if ([] === $propertyNames) {
            throw new MappingFailed('Column name as string are only supported if the tabular data has a non-empty header.');
        }

        /** @var int<0, max>|false $index */
        $index = array_search($offset, $propertyNames, true);
        if (false === $index) {
            throw new MappingFailed('The offset `'.$offset.'` could not be found in the header; Pleaser verify your header data.');
        }

        return new PropertySetter($accessor, $index, $cast);
    }

    /**
     * @throws MappingFailed
     */
    private function getTypeCasting(Cell $cell, ReflectionProperty|ReflectionMethod $accessor): TypeCasting
    {
        if (array_key_exists('reflectionProperty', $cell->castArguments)) {
            throw new MappingFailed('The key `reflectionProperty` can not be used with `castArguments`.');
        }

        $reflectionProperty = match (true) {
            $accessor instanceof ReflectionMethod => $accessor->getParameters()[0],
            $accessor instanceof ReflectionProperty => $accessor,
        };

        $typeCaster = $cell->cast;
        if (null === $typeCaster) {
            return $this->resolveTypeCasting($reflectionProperty, $cell->castArguments);
        }

        if (!in_array(TypeCasting::class, class_implements($typeCaster), true)) {
            throw new MappingFailed('The class `'.$typeCaster.'` does not implements the `'.TypeCasting::class.'` interface.');
        }

        try {
            /** @var TypeCasting $cast */
            $cast = new $typeCaster(...$cell->castArguments, ...['reflectionProperty' => $reflectionProperty]);

            return $cast;
        } catch (Throwable $exception) {
            if ($exception instanceof MappingFailed) {
                throw $exception;
            }

            throw new MappingFailed(message:'Unable to instantiate a casting mechanism. Please verify your casting arguments', previous: $exception);
        }
    }

    private function resolveTypeCasting(ReflectionProperty|ReflectionParameter $reflectionProperty, array $arguments = []): TypeCasting
    {
        $exception = new MappingFailed(match (true) {
            $reflectionProperty instanceof ReflectionParameter => 'The setter method argument `'.$reflectionProperty->getName().'` must be typed with a supported type.',
            $reflectionProperty instanceof ReflectionProperty => 'The property `'.$reflectionProperty->getName().'` must be typed with a supported type.',
        });

        $reflectionType = $reflectionProperty->getType() ?? throw $exception;

        try {
            $arguments['reflectionProperty'] = $reflectionProperty;

            return ClosureCasting::supports($reflectionProperty) ?
                new ClosureCasting(...$arguments) :
                match (Type::tryFromReflectionType($reflectionType)) {
                    Type::Mixed, Type::Null, Type::String => new CastToString(...$arguments),
                    Type::Iterable, Type::Array => new CastToArray(...$arguments),
                    Type::False, Type::True, Type::Bool => new CastToBool(...$arguments),
                    Type::Float => new CastToFloat(...$arguments),
                    Type::Int => new CastToInt(...$arguments),
                    Type::Date => new CastToDate(...$arguments),
                    Type::Enum => new CastToEnum(...$arguments),
                    default => throw $exception,
                };
        } catch (MappingFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MappingFailed(message:'Unable to load the casting mechanism. Please verify your casting arguments', previous: $exception);
        }
    }
}

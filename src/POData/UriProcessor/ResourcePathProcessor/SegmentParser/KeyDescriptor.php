<?php

namespace POData\UriProcessor\ResourcePathProcessor\SegmentParser;

use POData\Common\InvalidOperationException;
use POData\Common\Messages;
use POData\Common\ODataException;
use POData\ObjectModel\ODataProperty;
use POData\Providers\Metadata\ResourceSet;
use POData\Providers\Metadata\ResourceType;
use POData\Providers\Metadata\Type\Boolean;
use POData\Providers\Metadata\Type\DateTime;
use POData\Providers\Metadata\Type\Decimal;
use POData\Providers\Metadata\Type\Double;
use POData\Providers\Metadata\Type\Guid;
use POData\Providers\Metadata\Type\Int32;
use POData\Providers\Metadata\Type\Int64;
use POData\Providers\Metadata\Type\IType;
use POData\Providers\Metadata\Type\Null1;
use POData\Providers\Metadata\Type\Single;
use POData\Providers\Metadata\Type\StringType;
use POData\UriProcessor\QueryProcessor\ExpressionParser\ExpressionLexer;
use POData\UriProcessor\QueryProcessor\ExpressionParser\ExpressionTokenId;

/**
 * Class KeyDescriptor.
 *
 * A type used to represent Key (identifier) for an entity (resource), This class
 * can parse an Astoria KeyPredicate, KeyPredicate will be in one of the following
 * two formats:
 *  1) KeyValue                                      : If the Entry has a single key
 *                                                     Property the predicate may
 *                                                     include only the value of the
 *                                                     key Property.
 *      e.g. 'ALFKI' in Customers('ALFKI')
 *  2) Property = KeyValue [, Property = KeyValue]*  : If the key is made up of two
 *                                                     or more Properties, then its
 *                                                     value must be stated using
 *                                                     name/value pairs.
 *      e.g. 'ALFKI' in Customers(CustomerID = 'ALFKI'),
 *          "OrderID=10248,ProductID=11" in Order_Details(OrderID=10248,ProductID=11)
 *
 * Entity's identifier is a collection of value for key properties. These values
 * can be named or positional, depending on how they were specified in the URI.
 *  e.g. Named values:
 *         Customers(CustomerID = 'ALFKI'), Order_Details(OrderID=10248,ProductID=11)
 *       Positional values:
 *         Customers('ALFKI'), Order_Details(10248, 11)
 * Note: Currently WCF Data Service does not support multiple 'Positional values' so
 *       Order_Details(10248, 11) is not valid, but this class can parse both types.
 * Note: This type is also used to parse and validate skiptoken value as they are
 *       comma separated positional values.
 */
class KeyDescriptor
{
    /**
     * Holds collection of named key values
     * For e.g. the keypredicate Order_Details(OrderID=10248,ProductID=11) will
     * stored in this array as:
     * Array([OrderID] => Array( [0] => 10248 [1] => Object(Int32)),
     *       [ProductID] => Array( [0] => 11 [1] => Object(Int32)))
     * Note: This is mutually exclusive with $_positionalValues. These values
     * are not validated against entity's ResourceType, validation will happen
     * once validate function is called, $_validatedNamedValues will hold
     * validated values.
     *
     * @var array
     */
    private $namedValues = [];

    /**
     * Holds collection of positional key values
     * For e.g. the keypredicate Order_Details(10248, 11) will
     * stored in this array as:
     * Array([0] => Array( [0] => 10248 [1] => Object(Int32)),
     *       [1] => Array( [0] => 11 [1] => Object(Int32)))
     * Note: This is mutually exclusive with $_namedValues. These values are not
     * validated against entity's ResourceType, validation will happen once validate
     * function is called, $_validatedNamedValues will hold validated values.
     *
     * @var array
     */
    private $positionalValues = [];

    /**
     * Holds collection of positional or named values as named values. The validate
     * function populates this collection.
     *
     * @var array
     */
    private $validatedNamedValues = [];

    /**
     * Creates new instance of KeyDescriptor
     * Note: The arguments $namedValues and $positionalValues are mutually
     * exclusive. Either both or one will be empty array.
     *
     * @param array $namedValues      Collection of named key values
     * @param array $positionalValues Collection of positional key values
     */
    private function __construct(array $namedValues, array $positionalValues)
    {
        $namedCount = count($namedValues);
        $posCount = count($positionalValues);
        assert(0 == min($namedCount, $posCount), 'At least one of named and positional values arrays must be empty');
        if (0 < $namedCount) {
            $keys = array_keys($namedValues);
            for ($i = 0; $i < $namedCount; $i++) {
                $namedValues[$keys[$i]][0] = urldecode($namedValues[$keys[$i]][0]);
            }
        }
        if (0 < $posCount) {
            for ($i = 0; $i < $posCount; $i++) {
                $positionalValues[$i][0] = urldecode($positionalValues[$i][0]);
            }
        }
        $this->namedValues = $namedValues;
        $this->positionalValues = $positionalValues;
        $this->validatedNamedValues = [];
    }

    /**
     * @param  string             $keyString
     * @param  bool               $isKey
     * @param  KeyDescriptor|null $keyDescriptor
     * @return bool
     */
    protected static function parseAndVerifyRawKeyPredicate($keyString, $isKey, KeyDescriptor &$keyDescriptor = null)
    {
        $result = self::tryParseKeysFromRawKeyPredicate(
            $keyString,
            $isKey,
            !$isKey,
            $keyDescriptor
        );
        assert(true === $result || false === $result, 'Result must be boolean');
        assert($result === isset($keyDescriptor), 'Result must match existence of keyDescriptor');
        return $result;
    }

    /**
     * Gets collection of named key values.
     *
     * @return array[]
     */
    public function getNamedValues()
    {
        return $this->namedValues;
    }

    /**
     * Gets collection of positional key values.
     *
     * @return array[]
     */
    public function getPositionalValues()
    {
        return $this->positionalValues;
    }

    /**
     * Gets collection of positional key values by reference.
     *
     * @return array[]
     */
    public function &getPositionalValuesByRef()
    {
        return $this->positionalValues;
    }

    /**
     * Gets validated named key values, this array will be populated
     * in validate function.
     *
     * @throws InvalidOperationException If this function invoked before invoking validate function
     *
     * @return array[]
     */
    public function getValidatedNamedValues()
    {
        if (empty($this->validatedNamedValues)) {
            throw new InvalidOperationException(
                Messages::keyDescriptorValidateNotCalled()
            );
        }

        return $this->validatedNamedValues;
    }

    /**
     * Checks whether the key values have name.
     *
     * @return bool
     */
    public function areNamedValues()
    {
        return !empty($this->namedValues);
    }

    /**
     * Check whether this KeyDescription has any key values.
     *
     * @return bool
     */
    public function isEmpty()
    {
        return empty($this->namedValues)
             && empty($this->positionalValues);
    }

    /**
     * Gets number of values in the key.
     *
     * @return int
     */
    public function valueCount()
    {
        if ($this->isEmpty()) {
            return 0;
        } elseif (!empty($this->namedValues)) {
            return count($this->namedValues);
        }

        return count($this->positionalValues);
    }

    /**
     * Attempts to parse value(s) of resource key(s) from the given key predicate
     *  and creates instance of KeyDescription representing the same, Once parsing
     *  is done one should call validate function to validate the created
     *  KeyDescription.
     *
     * @param string             $keyPredicate  The predicate to parse
     * @param KeyDescriptor|null $keyDescriptor On return, Description of key after parsing
     *
     * @return bool True if the given values were parsed; false if there was a syntax error
     */
    public static function tryParseKeysFromKeyPredicate(
        $keyPredicate,
        KeyDescriptor &$keyDescriptor = null
    ) {
        $isKey = true;
        $keyString = $keyPredicate;
        return self::parseAndVerifyRawKeyPredicate($keyString, $isKey, $keyDescriptor);
    }

    /**
     * Attempt to parse comma separated values representing a skiptoken and creates
     * instance of KeyDescriptor representing the same.
     *
     * @param string        $skipToken      The skiptoken value to parse
     * @param KeyDescriptor &$keyDescriptor On return, Description of values
     *                                      after parsing
     *
     * @return bool True if the given values were parsed; false if there was a syntax error
     */
    public static function tryParseValuesFromSkipToken($skipToken, &$keyDescriptor)
    {
        $isKey = false;
        $keyString = $skipToken;
        return self::parseAndVerifyRawKeyPredicate($keyString, $isKey, $keyDescriptor);
    }

    /**
     * Validate this KeyDescriptor, If valid, this function populates
     * _validatedNamedValues array with key as keyName and value as an array of
     * key value and key type.
     *
     * @param string       $segmentAsString The segment in the form identifier
     *                                      (keyPredicate) which this descriptor
     *                                      represents
     * @param ResourceType $resourceType    The type of the identifier in the segment
     *
     * @throws ODataException If validation fails
     */
    public function validate($segmentAsString, ResourceType $resourceType)
    {
        if ($this->isEmpty()) {
            $this->validatedNamedValues = [];

            return;
        }

        $keyProperties = $resourceType->getKeyProperties();
        $keyPropertiesCount = count($keyProperties);
        if (!empty($this->namedValues)) {
            if (count($this->namedValues) != $keyPropertiesCount) {
                throw ODataException::createSyntaxError(
                    Messages::keyDescriptorKeyCountNotMatching(
                        $segmentAsString,
                        $keyPropertiesCount,
                        count($this->namedValues)
                    )
                );
            }

            foreach ($keyProperties as $keyName => $keyResourceProperty) {
                if (!array_key_exists($keyName, $this->namedValues)) {
                    $keysAsString = null;
                    foreach (array_keys($keyProperties) as $key) {
                        $keysAsString .= $key . ', ';
                    }

                    $keysAsString = rtrim($keysAsString, ' ,');
                    throw ODataException::createSyntaxError(
                        Messages::keyDescriptorMissingKeys(
                            $segmentAsString,
                            $keysAsString
                        )
                    );
                }

                $typeProvided = $this->namedValues[$keyName][1];
                $expectedType = $keyResourceProperty->getInstanceType();
                assert($expectedType instanceof IType, get_class($expectedType));
                if (!$expectedType->isCompatibleWith($typeProvided)) {
                    throw ODataException::createSyntaxError(
                        Messages::keyDescriptorInCompatibleKeyType(
                            $segmentAsString,
                            $keyName,
                            $expectedType->getFullTypeName(),
                            $typeProvided->getFullTypeName()
                        )
                    );
                }

                $this->validatedNamedValues[$keyName] = $this->namedValues[$keyName];
            }
        } else {
            if (count($this->positionalValues) != $keyPropertiesCount) {
                throw ODataException::createSyntaxError(
                    Messages::keyDescriptorKeyCountNotMatching(
                        $segmentAsString,
                        $keyPropertiesCount,
                        count($this->positionalValues)
                    )
                );
            }

            $i = 0;
            foreach ($keyProperties as $keyName => $keyResourceProperty) {
                $typeProvided = $this->positionalValues[$i][1];
                $expectedType = $keyResourceProperty->getInstanceType();
                assert($expectedType instanceof IType, get_class($expectedType));

                if (!$expectedType->isCompatibleWith($typeProvided)) {
                    throw ODataException::createSyntaxError(
                        Messages::keyDescriptorInCompatibleKeyTypeAtPosition(
                            $segmentAsString,
                            $keyResourceProperty->getName(),
                            $i,
                            $expectedType->getFullTypeName(),
                            $typeProvided->getFullTypeName()
                        )
                    );
                }

                $this->validatedNamedValues[$keyName]
                    = $this->positionalValues[$i];
                ++$i;
            }
        }
    }

    /**
     * Attempts to parse value(s) of resource key(s) from the key predicate and
     * creates instance of KeyDescription representing the same, Once parsing is
     * done, one should call validate function to validate the created KeyDescription.
     *
     * @param string        $keyPredicate     The key predicate to parse
     * @param bool          $allowNamedValues Set to true if parser should accept
     *                                        named values(Property = KeyValue),
     *                                        if false then parser will fail on
     *                                        such constructs
     * @param bool          $allowNull        Set to true if parser should accept
     *                                        null values for positional key
     *                                        values, if false then parser will
     *                                        fail on seeing null values
     * @param KeyDescriptor &$keyDescriptor   On return, Description of key after
     *                                        parsing
     *
     * @return bool True if the given values were parsed; false if there was a syntax error
     */
    private static function tryParseKeysFromRawKeyPredicate(
        $keyPredicate,
        $allowNamedValues,
        $allowNull,
        &$keyDescriptor
    ) {
        $expressionLexer = new ExpressionLexer($keyPredicate);
        $currentToken = $expressionLexer->getCurrentToken();

        //Check for empty predicate e.g. Customers(  )
        if ($currentToken->Id == ExpressionTokenId::END) {
            $keyDescriptor = new self([], []);

            return true;
        }

        $namedValues = [];
        $positionalValues = [];

        do {
            if (($currentToken->Id == ExpressionTokenId::IDENTIFIER)
                && $allowNamedValues
            ) {
                //named and positional values are mutually exclusive
                if (!empty($positionalValues)) {
                    return false;
                }

                //expecting keyName=keyValue, verify it
                $identifier = $currentToken->getIdentifier();
                $expressionLexer->nextToken();
                $currentToken = $expressionLexer->getCurrentToken();
                if ($currentToken->Id != ExpressionTokenId::EQUAL) {
                    return false;
                }

                $expressionLexer->nextToken();
                $currentToken = $expressionLexer->getCurrentToken();
                if (!$currentToken->isKeyValueToken()) {
                    return false;
                }

                if (array_key_exists($identifier, $namedValues)) {
                    //Duplication of KeyName not allowed
                    return false;
                }

                //Get type of keyValue and validate keyValue
                $outValue = $outType = null;
                if (!self::getTypeAndValidateKeyValue(
                    $currentToken->Text,
                    $currentToken->Id,
                    $outValue,
                    $outType
                )
                ) {
                    return false;
                }

                $namedValues[$identifier] = [$outValue, $outType];
            } elseif ($currentToken->isKeyValueToken()
                || ($currentToken->Id == ExpressionTokenId::NULL_LITERAL && $allowNull)
            ) {
                //named and positional values are mutually exclusive
                if (!empty($namedValues)) {
                    return false;
                }

                //Get type of keyValue and validate keyValue
                $outValue = $outType = null;
                if (!self::getTypeAndValidateKeyValue(
                    $currentToken->Text,
                    $currentToken->Id,
                    $outValue,
                    $outType
                )
                ) {
                    return false;
                }

                $positionalValues[] = [$outValue, $outType];
            } else {
                return false;
            }

            $expressionLexer->nextToken();
            $currentToken = $expressionLexer->getCurrentToken();
            if ($currentToken->Id == ExpressionTokenId::COMMA) {
                $expressionLexer->nextToken();
                $currentToken = $expressionLexer->getCurrentToken();
                //end of text and comma, Trailing comma not allowed
                if ($currentToken->Id == ExpressionTokenId::END) {
                    return false;
                }
            }
        } while ($currentToken->Id != ExpressionTokenId::END);

        $keyDescriptor = new self($namedValues, $positionalValues);

        return true;
    }

    /**
     * Get the type of an Astoria URI key value, validate the value against the type. If valid, this function
     * provides the PHP value equivalent to the Astoria URI key value.
     *
     * @param string            $value     The Astoria URI key value
     * @param ExpressionTokenId $tokenId   The tokenId for $value literal
     * @param mixed|null        &$outValue After the invocation, this parameter holds the PHP equivalent to $value,
     *                                     if $value is not valid then this parameter will be null
     * @param IType|null        &$outType  After the invocation, this parameter holds the type of $value, if $value is
     *                                     not a valid key value type then this parameter will be null
     *
     * @return bool True if $value is a valid type, else false
     */
    private static function getTypeAndValidateKeyValue($value, $tokenId, &$outValue, &$outType)
    {
        switch ($tokenId) {
            case ExpressionTokenId::BOOLEAN_LITERAL:
                $outType = new Boolean();
                break;
            case ExpressionTokenId::DATETIME_LITERAL:
                $outType = new DateTime();
                break;
            case ExpressionTokenId::GUID_LITERAL:
                $outType = new Guid();
                break;
            case ExpressionTokenId::STRING_LITERAL:
                $outType = new StringType();
                break;
            case ExpressionTokenId::INTEGER_LITERAL:
                $outType = new Int32();
                break;
            case ExpressionTokenId::DECIMAL_LITERAL:
                $outType = new Decimal();
                break;
            case ExpressionTokenId::DOUBLE_LITERAL:
                $outType = new Double();
                break;
            case ExpressionTokenId::INT64_LITERAL:
                $outType = new Int64();
                break;
            case ExpressionTokenId::SINGLE_LITERAL:
                $outType = new Single();
                break;
            case ExpressionTokenId::NULL_LITERAL:
                $outType = new Null1();
                break;
            default:
                $outType = null;

                return false;
        }

        if (!$outType->validate($value, $outValue)) {
            $outType = $outValue = null;

            return false;
        }

        return true;
    }

    /**
     * Generate relative edit url for this key descriptor and supplied resource set.
     *
     * @param ResourceSet $resourceSet
     *
     * @throws \InvalidArgumentException
     * @return string
     */
    public function generateRelativeUri(ResourceSet $resourceSet)
    {
        $resourceType = $resourceSet->getResourceType();
        $keys = $resourceType->getKeyProperties();

        $namedKeys = $this->getNamedValues();
        assert(0 !== count($keys), 'count($keys) == 0');
        if (count($keys) !== count($namedKeys)) {
            $msg = 'Mismatch between supplied key predicates and number of keys defined on resource set';
            throw new \InvalidArgumentException($msg);
        }
        $editUrl = $resourceSet->getName() . '(';
        $comma = null;
        foreach ($keys as $keyName => $resourceProperty) {
            if (!array_key_exists($keyName, $namedKeys)) {
                $msg = 'Key predicate '.$keyName.' not present in named values';
                throw new \InvalidArgumentException($msg);
            }
            $keyType = $resourceProperty->getInstanceType();
            assert($keyType instanceof IType, '$keyType not instanceof IType');
            $keyValue = $namedKeys[$keyName][0];
            $keyValue = $keyType->convertToOData($keyValue);

            $editUrl .= $comma . $keyName . '=' . $keyValue;
            $comma = ',';
        }

        $editUrl .= ')';

        return $editUrl;
    }

    /**
     * Convert validated named values into an array of ODataProperties.
     *
     * return array[]
     */
    public function getODataProperties()
    {
        $values = $this->getValidatedNamedValues();
        $result = [];

        foreach ($values as $propName => $propDeets) {
            assert(2 == count($propDeets));
            assert($propDeets[1] instanceof IType);
            $property = new ODataProperty();
            $property->name = strval($propName);
            $property->value = $propDeets[1]->convert($propDeets[0]);
            $property->typeName = $propDeets[1]->getFullTypeName();
            $result[$propName] = $property;
        }

        return $result;
    }
}

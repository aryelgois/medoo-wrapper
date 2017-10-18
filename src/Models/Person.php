<?php
/**
 * This Software is part of aryelgois\Medools and is provided "as is".
 *
 * @see LICENSE
 */

namespace aryelgois\Medools\Models;

use aryelgois\Utils;
use aryelgois\Medools;

/**
 * A Person object defines someone in the real world. It is a basic setup and
 * you must to extend for your use case.
 *
 * @author Aryel Mota Góis
 * @license MIT
 * @link https://www.github.com/aryelgois/Medools
 */
abstract class Person extends Medools\Model
{
    const DATABASE_NAME_KEY = 'my_app'; // example

    const DATABASE_TABLE = 'people';

    /**
     * Rows fetched from `people`
     *
     * @const string[]
     */
    const ROWS_PERSON = ['id', 'name', 'document', 'birthday', 'update'];

    /**
     * Reads a Person data from the Database
     *
     * @param mixed[] $where \Medoo\Medoo where clause
     *
     * @return boolean For success or failure
     */
    public function read($where)
    {
        $this->data = null;
        $this->valid = false;
        $this->changes = null;

        $person = $this->database->get(
            static::DATABASE_TABLE,
            static::ROWS_PERSON,
            $where
        );

        if ($person) {
            $this->data = $person;
            $this->valid = true;
        }

        return $this->valid;
    }

    /**
     * Validates Person's document as Brazilian CPF or CNPJ
     *
     * @return mixed[] With keys 'type' and 'valid'
     * @return false   If document is invalid
     * @return null    If a document row was not found
     */
    public function documentValidate()
    {
        if (!isset($this->data['document'])) {
            return null;
        }
        return Utils\Validation::document($this->data['document']);
    }

    /**
     * Formats Person's Document
     *
     * @param boolean $prepend If should prepend the document name
     *
     * @return string Formatted document
     * @return string Unformatted document if it is invalid
     * @return null   If a document row was not found
     */
    public function documentFormat($prepend = false)
    {
        if (!isset($this->data['document'])) {
            return null;
        }
        return Utils\Format::document($this->data['document'], $prepend);
    }
}

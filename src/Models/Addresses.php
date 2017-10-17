<?php
/**
 * This Software is part of aryelgois\MedooWrapper and is provided "as is".
 *
 * @see LICENSE
 */

namespace aryelgois\MedooWrapper\Models;

use aryelgois\MedooWrapper;

/**
 * Get every Address entry from the Database
 *
 * Useful to fill a form
 *
 * It is built on top of aryelgois\databases\Address, which means it expects
 * you have a database following that scheme in your server.
 *
 * @see https://www.github.com/aryelgois/databases
 *
 * @author Aryel Mota Góis
 * @license MIT
 * @link https://www.github.com/aryelgois/medoo-wrapper
 */
abstract class Addresses extends MedooWrapper\DatabaseObject
{
    /*
     * This class does not define the const DATABASE_TABLE because it uses more
     * than one table
     */

    /**
     * Creates a new Addresses object
     */
    public function __construct()
    {
        parent::__construct();
        $this->data = [];
    }

    /**
     * Fetches all countries from the Database and caches it in the object
     *
     * @param boolean $reload If should reload the cache
     *
     * @return array[] With fetched rows
     */
    public function loadCountries($reload = false)
    {
        if ($reload || !isset($this->data['countries'])) {
            $this->data['countries'] = $this->database->select(
                'countries',
                Address::ROWS_COUNTRY
            );
        }
        return $this->data['countries'];
    }

    /**
     * Fetches all countries from a Country and caches it in the object
     *
     * @param integer $country_id Country Id to reduce the query
     * @param boolean $reload     If should reload the cache
     *
     * @return array[] With fetched rows
     */
    public function loadStates($country_id, $reload = false)
    {
        if ($reload || !isset($this->data['states'][$country_id])) {
            $this->data['states'][$country_id] = $this->database->select(
                'states',
                Address::ROWS_STATE,
                ['country' => $country_id]
            );
        }
        return $this->data['states'][$country_id];
    }

    /**
     * Fetches all counties from a State and caches it in the object
     *
     * @param integer $state_id State Id to reduce the query
     * @param boolean $reload   If should reload the cache
     *
     * @return array[] With fetched rows
     */
    public function loadCounties($state_id, $reload = false)
    {
        if ($reload || !isset($this->data['counties'][$state_id])) {
            $this->data['counties'][$state_id] = $this->database->select(
                'counties',
                Address::ROWS_COUNTY,
                ['state' => $state_id]
            );
        }
        return $this->data['counties'][$state_id];
    }
}
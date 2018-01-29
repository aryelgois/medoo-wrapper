<?php
/**
 * This Software is part of aryelgois/Medools and is provided "as is".
 *
 * @see LICENSE
 */

namespace aryelgois\Medools;

use aryelgois\Utils\Utils;
use aryelgois\Medools\Exceptions\{
    ForeignConstraintException,
    MissingColumnException,
    NotForeignColumnException,
    ReadOnlyModelException,
    UnknownColumnException
};

/**
 * Wrapper on catfan/Medoo
 *
 * Each model class maps to one Table in the Database, and each model object
 * maps to one row.
 *
 * @author Aryel Mota Góis
 * @license MIT
 * @link https://www.github.com/aryelgois/Medools
 */
abstract class Model implements \JsonSerializable
{
    /*
     * Model configuration
     * =========================================================================
     */

    /**
     * Database name key in the config file
     *
     * @const string
     */
    const DATABASE = 'default';

    /**
     * Database's Table the model works with
     *
     * The recomended is to use a plural name for the table and it's singular in
     * the model name
     *
     * @const string
     */
    const TABLE = '';

    /**
     * Columns the model expects to exist
     *
     * @const string[]
     */
    const COLUMNS = ['id'];

    /**
     * Primary Key column or columns
     *
     * @const string[]
     */
    const PRIMARY_KEY = ['id'];

    /**
     * Auto Increment column
     *
     * This column is ignored by update()
     *
     * @const string|null
     */
    const AUTO_INCREMENT = 'id';

    /**
     * List of optional columns
     *
     * List here all columns which have a default value (e.g. timestamp) or are
     * nullable. AUTO_INCREMENT is always optional and does not need to be here.
     *
     * @const string[]
     */
    const OPTIONAL_COLUMNS = [];

    /**
     * Foreign Keys map
     *
     * A map of zero or more columns in this model, each pointing to a column in
     * another model
     *
     * EXAMPLE:
     *     [
     *         'local_column' => [
     *             'Fully\Qualified\ClassName',
     *             'foreign_column'
     *         ],
     *     ];
     *
     * @const array[]
     */
    const FOREIGN_KEYS = [];

    /**
     * If __set(), save(), update() and delete() are disabled
     *
     * @const boolean
     */
    const READ_ONLY = false;

    /**
     * If delete() actually removes the row or if it changes a column
     *
     * @const string|null Column affected by the soft delete
     */
    const SOFT_DELETE = null;

    /**
     * How the soft delete works
     *
     * Possible values:
     * - deleted: 0 or 1
     * - active:  1 or 0
     * - stamp:   null or current timestamp
     *
     * @const string
     */
    const SOFT_DELETE_MODE = 'deleted';

    /*
     * Model data
     * =========================================================================
     */

    /**
     * Changes done by __set() to be saved by save() or update()
     *
     * @var mixed[]
     */
    protected $changes = [];

    /**
     * Keeps fetched data
     *
     * @var mixed[]
     */
    protected $data;

    /**
     * List of foreign models
     *
     * @var Model[]
     */
    protected $foreign = [];

    /*
     * Magic methods
     * =========================================================================
     */

    /**
     * Creates a new Model object
     *
     * @param mixed $where @see load(). If null, a fresh model is created
     *
     * @throws \InvalidArgumentException  @see load()
     * @throws ForeignConstraintException @see load()
     * @throws \InvalidArgumentException  If could not load from Database
     */
    public function __construct($where = null)
    {
        if ($where !== null && !$this->load($where)) {
            throw new \InvalidArgumentException('Could not load from Database');
        }
    }

    /**
     * Returns the stored data in a column
     *
     * If a Foreign column is requested, returns the corresponding Model instead
     *
     * @param string $column A known column
     *
     * @return mixed
     *
     * @throws UnknownColumnException
     */
    public function __get($column)
    {
        if (!in_array($column, static::COLUMNS)) {
            throw new UnknownColumnException();
        }

        if (array_key_exists($column, static::FOREIGN_KEYS)) {
            return $this->foreign[$column] ?? null;
        }
        return $this->changes[$column] ?? $this->data[$column];
    }

    /**
     * Checks if a column has some value
     *
     * @param string  $column A known column
     *
     * @return boolean
     *
     * @throws UnknownColumnException @see __get()
     */
    public function __isset($column)
    {
        return null !== $this->__get($column);
    }

    /**
     * Changes the value in a column
     *
     * NOTE:
     * - Changes need to be saved in the Database with save() or update($column)
     *
     * @param string $column A known column
     * @param mixed  $value  The new value
     *
     * @throws ReadOnlyModelException
     * @throws UnknownColumnException
     * @throws ForeignConstraintException @see loadForeign()
     */
    public function __set($column, $value)
    {
        if (static::READ_ONLY) {
            throw new ReadOnlyModelException();
        }
        if (!in_array($column, static::COLUMNS)) {
            throw new UnknownColumnException();
        }

        if (array_key_exists($column, static::FOREIGN_KEYS)) {
            $foreign_map = static::FOREIGN_KEYS[$column];
            if ($value instanceof $foreign_map[0]) {
                $this->foreign[$column] = $value;
                $this->changes[$column] = $value->data[$foreign_map[1]];
                return;
            } else {
                $this->loadForeign($column, $value);
            }
        }
        $this->changes[$column] = $value;
    }

    /**
     * Sets a column to NULL
     *
     * @see __set()
     *
     * @param [type] $column A known column
     *
     * @throws ReadOnlyModelException
     * @throws UnknownColumnException
     * @throws ForeignConstraintException
     */
    public function __unset($column)
    {
        $this->__set($column, null);
    }

    /*
     * Basic methods
     * =========================================================================
     */

    /**
     * Cleans data keys, removing unwanted columns
     *
     * @todo Ignore auto timestamp column
     * @todo Option to tell custom ignored columns
     *
     * @param string[] $data Data to be cleaned
     * @param string   $data Which method will use the result
     *
     * @return string[]
     */
    protected static function dataCleanup($data)
    {
        $whitelist = static::COLUMNS;
        $blacklist = [static::AUTO_INCREMENT];

        $data = Utils::arrayWhitelist($data, $whitelist);
        $data = Utils::arrayBlacklist($data, $blacklist);
        return $data;
    }

    /**
     * Returns data in model's Table
     *
     * @param mixed[]  $where   \Medoo\Medoo where clause
     * @param string[] $columns Specify which columns you want
     *
     * @return array[]
     *
     * @throws UnknownColumnException If any item in $columns is invalid
     */
    public static function dump($where = [], $columns = [])
    {
        if (empty($columns)) {
            $columns = static::COLUMNS;
        } elseif (!empty($invalid = array_diff($columns, static::COLUMNS))) {
            throw new UnknownColumnException($invalid);
        }

        $database = self::getDatabase();
        return $database->select(static::TABLE, $columns, $where);
    }

    /**
     * Selects Current Timestamp from Database
     *
     * Useful to keep timezone consistent
     *
     * @return string
     */
    public static function getCurrentTimestamp()
    {
        $database = self::getDatabase();
        $sql = 'SELECT CURRENT_TIMESTAMP';
        return $database->query($sql)->fetch(\PDO::FETCH_NUM)[0];
    }

    /**
     * Returns a database connection
     *
     * @return \Medoo\Medoo
     */
    final public static function getDatabase()
    {
        return MedooConnection::getInstance(static::DATABASE);
    }

    /**
     * Returns model's Primary Key
     *
     * NOTE:
     * - It returns the data saved in Database, changes by __set() are ignored
     *
     * @return mixed[] Usually it will contain an integer key
     * @return null    If the model was not saved yet
     */
    public function getPrimaryKey()
    {
        if ($this->data === null) {
            return null;
        }
        return Utils::arrayWhitelist($this->data, static::PRIMARY_KEY);
    }

    /**
     * Returns the stored data in an array
     *
     * @return mixed[]
     */
    public function jsonSerialize()
    {
        $data = array_replace($this->data ?? [], $this->changes);
        if (empty($data)) {
            return null;
        }
        foreach ($this->foreign as $column => $model) {
            $data[$column] = $model;
        }
        return $data;
    }

    /**
     * Exports this Model to ModelManager
     *
     * @return boolean For success or failure
     */
    protected function managerExport()
    {
        ModelManager::import($this);
    }

    /**
     * Updates this Model in the ModelManager
     *
     * @param string[] $old_primary_key
     */
    protected function managerUpdate($old_primary_key)
    {
        ModelManager::remove(array_merge([get_class($this)], $old_primary_key));
        $this->managerExport();
    }

    /**
     * Process $where, adding the PRIMARY_KEY if needed
     *
     * It allows the use of a simple value (e.g. string or integer) or a
     * simple array without specifing the PRIMARY_KEY column(s)
     *
     * @param mixed $where Value for Primary Key or \Medoo\Medoo where clause
     *
     * @return boolean For success or failure
     *
     * @throws \InvalidArgumentException  If $where is null
     * @throws \InvalidArgumentException  If could not solve Primary Key:
     *                                    - $where does not specify columns and
     *                                      does not match PRIMARY_KEY length
     */
    final public static function processWhere($where)
    {
        if ($where === null) {
            throw new \InvalidArgumentException('Primary Key can not be null');
        }
        $where = (array) $where;
        if (!Utils::arrayIsAssoc($where)) {
            $where = @array_combine(static::PRIMARY_KEY, $where);
            if ($where === false) {
                throw new \InvalidArgumentException(
                    'Could not solve Primary Key'
                );
            }
        }
        return $where;
    }

    /**
     * Reloads model data
     *
     * @return boolean For success or failure
     */
    public function reload()
    {
        return $this->load($this->getPrimaryKey());
    }

    /**
     * Cleans model data
     */
    protected function reset()
    {
        $this->changes = [];
        $this->data = null;
        $this->foreign = [];
    }

    /**
     * Changes the value in multiple columns
     *
     * @see __set()
     *
     * @param mixed[] $data An array of known columns => value
     *
     * @throws ReadOnlyModelException
     * @throws UnknownColumnException
     * @throws ForeignConstraintException
     */
    public function setMultiple($data)
    {
        foreach ($data as $column => $value) {
            $this->__set($column, $value);
        }
    }

    /**
     * Sets the SOFT_DELETE column to a undeleted state
     *
     * @return boolean For success or failure
     *
     * @throws ReadOnlyModelException
     * @throws \LogicException        If the Model is not soft-deletable
     * @throws \LogicException        If SOFT_DELETE_MODE is unknown
     */
    public function undelete()
    {
        if (static::READ_ONLY) {
            throw new ReadOnlyModelException();
        }
        if (static::SOFT_DELETE === null) {
            throw new \LogicException('Model is not soft-deletable');
        }

        $database = self::getDatabase();
        $column = static::SOFT_DELETE;

        switch (static::SOFT_DELETE_MODE) {
            case 'deleted':
                $this->__set($column, 0);
                break;

            case 'active':
                $this->__set($column, 1);
                break;

            case 'stamp':
                $this->__set($column, null);
                break;

            default:
                throw new \LogicException(
                    "Unknown mode '" . static::SOFT_DELETE_MODE . "'"
                );
                break;
        }

        return $this->update($column);
    }

    /**
     * Updates a foreign model to a new row
     *
     * It tests if $column is a valid foreign column
     *
     * @param string $column A column in FOREIGN_KEYS keys
     * @param mixed  $value  A value in the foreign table
     *
     * @throws UnknownColumnException
     * @throws NotForeignColumnException
     * @throws ForeignConstraintException
     */
    protected function loadForeign($column, $value)
    {
        if (!in_array($column, static::COLUMNS)) {
            throw new UnknownColumnException();
        }
        if (!array_key_exists($column, static::FOREIGN_KEYS)) {
            throw new NotForeignColumnException();
        }

        $foreign_map = static::FOREIGN_KEYS[$column];

        if ($value === null) {
            unset($this->foreign[$column]);
            return;
        }
        $foreign = ModelManager::getInstance(
            $foreign_map[0],
            [$foreign_map[1] => $value]
        );

        if ($foreign) {
            $this->foreign[$column] = $foreign;
        } else {
            throw new ForeignConstraintException(static::class, $column);
        }
    }

    /**
     * Tells if the model has valid data
     *
     * It may change the data to remove unwanted content
     *
     * @param mixed[] $data Data to be validated
     * @param boolean $full If $data is supposed to contain all columns
     *                      (optional columns not required) :D
     *
     * @return mixed[] Valid data
     *
     * @throws MissingColumnException
     * @throws UnknownColumnException
     * @throws \UnexpectedValueException If Invalid data is found
     */
    protected static function validate($data, $full)
    {
        $columns = array_keys($data);

        /*
         * Check missing columns
         */
        if ($full) {
            $required = array_diff(
                static::COLUMNS,
                static::OPTIONAL_COLUMNS,
                [ // implicit optional columns
                    static::AUTO_INCREMENT,
                    static::SOFT_DELETE,
                ]
            );
            $missing = array_diff($required, $columns);
            if (!empty($missing)) {
                throw new MissingColumnException($missing);
            }
        }

        /*
         * Check unknown columns
         */
        $unknown = array_diff($columns, static::COLUMNS);
        if (!empty($unknown)) {
            throw new UnknownColumnException($unknown);
        }

        /*
         * Expanded validation
         */
        $result = static::validateHook($data, $full);
        if ($result === false) {
            throw new \UnexpectedValueException('Invalid data');
        } elseif (is_array($result)) {
            $data = (empty(Utils::arrayUniqueDiffKey($data, $result)))
                  ? $result
                  : array_replace($data, $result);
        }

        return $data;
    }

    /*
     * CRUD methods
     * =========================================================================
     */

    /**
     * Creates a new row in the Table or updates it with new data
     *
     * @see validate() Throws
     *
     * @return boolean For success or failure
     *
     * @throws ReadOnlyModelException
     */
    public function save()
    {
        if (static::READ_ONLY) {
            throw new ReadOnlyModelException();
        }

        if (empty($this->changes)) {
            return false;
        }

        $data = $this->changes;
        $data = static::validate($data, true);

        $old_primary_key = $this->getPrimaryKey();
        $update_manager = $this->data !== null && !empty(array_intersect(
            array_keys($data),
            static::PRIMARY_KEY
        ));

        $database = self::getDatabase();
        $stmt = ($this->data === null)
              ? $database->insert(static::TABLE, static::dataCleanup($data))
              : $database->update(static::TABLE, $data, $old_primary_key);

        if ($stmt->errorCode() == '00000') {
            if ($this->data === null) {
                /*
                 * It is prefered to load back because the Database may apply
                 * default values or alter some columns. Also, it updates
                 * foreign models.
                 *
                 * First, get the AUTO_INCREMENT
                 * Then, extract the PRIMARY_KEY
                 * Finally, load from Database
                 */
                $column = static::AUTO_INCREMENT;
                if ($column !== null) {
                    $data[$column] = $database->id();
                }
                $where = Utils::arrayWhitelist($data, static::PRIMARY_KEY);
                return $this->load($where);
            }
            $this->changes = [];
            $this->data = array_replace($this->data, $data);
            if ($update_manager) {
                $this->managerUpdate($old_primary_key);
            }
            return true;
        }
        return false;
    }

    /**
     * Loads a row from Table into the model
     *
     * @param mixed $where Value for Primary Key or \Medoo\Medoo where clause
     *
     * @return boolean For success or failure
     *
     * @throws \InvalidArgumentException  @see processWhere()
     * @throws ForeignConstraintException @see loadForeign()
     */
    public function load($where)
    {
        $where = self::processWhere($where);

        $old_primary_key = $this->getPrimaryKey();

        $database = self::getDatabase();
        $data = $database->get(static::TABLE, static::COLUMNS, $where);
        if ($data) {
            $this->reset();
            $this->data = $data;
            if ($old_primary_key) {
                $this->managerUpdate($old_primary_key);
            } else {
                $this->managerExport();
            }
            foreach (array_keys(static::FOREIGN_KEYS) as $column) {
                $this->loadForeign($column, $data[$column]);
            }
            return true;
        }

        return false;
    }

    /**
     * Selectively updates the model's row in the Database
     *
     * @see validate() Throws
     *
     * @param string|string[] $columns Specify which columns to update
     *
     * @return boolean For success or failure
     *
     * @throws ReadOnlyModelException
     * @throws \LogicException        If trying to update a fresh Model
     */
    public function update($columns)
    {
        if (static::READ_ONLY) {
            throw new ReadOnlyModelException();
        }
        if ($this->data === null) {
            throw new \LogicException('Can not update a fresh Model');
        }

        $columns = (array) $columns;
        $data = Utils::arrayWhitelist($this->changes, $columns);
        $data = static::validate($data, false);

        $old_primary_key = $this->getPrimaryKey();
        $update_manager = !empty(array_intersect(
            $columns,
            static::PRIMARY_KEY
        ));

        $database = self::getDatabase();
        $stmt = $database->update(static::TABLE, $data, $old_primary_key);
        if ($stmt->errorCode() == '00000') {
            $this->changes = Utils::arrayBlacklist($this->changes, $columns);
            $this->data = array_replace($this->data, $data);
            if ($update_manager) {
                $this->managerUpdate($old_primary_key);
            }
            return true;
        }

        return false;
    }

    /**
     * Removes model's row from the Table or sets the SOFT_DELETE column
     *
     * @return boolean For success or failure
     *
     * @throws ReadOnlyModelException
     * @throws \LogicException        If SOFT_DELETE_MODE is unknown
     */
    public function delete()
    {
        if (static::READ_ONLY) {
            throw new ReadOnlyModelException();
        }

        $database = self::getDatabase();
        $column = static::SOFT_DELETE;
        if ($column) {
            switch (static::SOFT_DELETE_MODE) {
                case 'deleted':
                    $this->__set($column, 1);
                    break;

                case 'active':
                    $this->__set($column, 0);
                    break;

                case 'stamp':
                    $this->__set($column, static::getCurrentTimestamp());
                    break;

                default:
                    throw new \LogicException(
                        "Unknown mode '" . static::SOFT_DELETE_MODE . "'"
                    );
                    break;
            }
            return $this->update($column);
        } else {
            $stmt = $database->delete(static::TABLE, $this->getPrimaryKey());
            ModelManager::remove($this);
            $this->reset();
            return ($stmt->rowCount() > 0);
        }
    }

    /*
     * Hook methods
     * =========================================================================
     */

    /**
     * Expanded validation
     *
     * Override this method to do specific validation for your model.
     * You may return an array of some $data keys with patched/validated data.
     *
     * @param mixed[] $data Data to be validated
     * @param boolean $full @see validate()
     *
     * @return mixed[] For success with a validation patch to $data
     * @return boolean For success or failure
     */
    protected static function validateHook($data, $full)
    {
        return true;
    }
}

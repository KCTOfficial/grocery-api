<?php

declare(strict_types=1);

namespace GroceryRestable\Resource;

use BadMethodCallException;
use GroceryRestable\Database\Schema\Table;
use GroceryRestable\Exception\ConfigurationException;

/**
 * Definition of an API resource (one table or view exposed as an endpoint).
 *
 * The fluent API mirrors Grocery CRUD so existing knowledge transfers directly:
 *
 *   $api->resource('employees')
 *       ->setSubject('Employee')
 *       ->setRelation('officeCode', 'offices', 'city')
 *       ->displayAs('officeCode', 'Office City')
 *       ->requiredFields('lastName')
 *       ->setFieldUpload('file_url', 'files');
 *
 * Grocery CRUD snake_case names (set_relation, unset_delete, callback_before_insert, ...)
 * are accepted as aliases of the camelCase methods.
 */
final class Resource
{
    public const OPERATIONS = ['list', 'read', 'create', 'update', 'delete', 'clone', 'export'];
    public const PERMISSIONS = ['select', 'insert', 'update', 'delete'];

    /**
     * Field types accepted by fieldType().
     */
    public const FIELD_TYPES = [
        'hidden',     // never returned; when "extras" is given the value is forced on write
        'invisible',  // never returned and never writable (only callbacks may set it)
        'readonly',   // returned, never writable
        'password',   // never returned, hashed with password_hash() on write
        'string', 'text', 'integer', 'number', 'boolean', 'true_false', 'date', 'datetime', 'time', 'json',
        'enum', 'dropdown',      // extras: list of allowed values (or value => label map)
        'set', 'multiselect',    // extras: list of allowed values; accepts arrays, stored comma separated
    ];

    private const ALIASES = [
        'set_relation_n_n' => 'setRelationNtoN',
        'set_relation_has_many' => 'setRelationHasMany',
        'field_type' => 'fieldType',
        'change_field_type' => 'fieldType',
        'unset_view' => 'unsetRead',
    ];

    private string $name;
    private ?string $connection = null;
    private string $table = '';
    private ?string $primaryKey = null;
    /** @var array<string, string> */
    private array $primaryKeys = [];
    private ?string $subject = null;
    private ?string $subjectPlural = null;
    private string $description = '';

    /** @var list<string> */
    private array $columns = [];
    /** @var list<string> */
    private array $unsetColumns = [];
    /** @var list<string> */
    private array $readFields = [];
    /** @var list<string> */
    private array $unsetReadFields = [];
    /** @var list<string> */
    private array $fields = [];
    /** @var list<string> */
    private array $addFields = [];
    /** @var list<string> */
    private array $editFields = [];
    /** @var list<string> */
    private array $unsetFields = [];
    /** @var list<string> */
    private array $unsetAddFields = [];
    /** @var list<string> */
    private array $unsetEditFields = [];
    /** @var list<string> */
    private array $requiredFields = [];
    /** @var list<string> */
    private array $uniqueFields = [];
    /** @var list<string> */
    private array $searchable = [];
    /** @var array<string, string> */
    private array $displayAs = [];
    /** @var array<string, array{type: string, extras: mixed}> */
    private array $fieldTypes = [];
    /** @var array<string, array{rules: string, label: string|null}> */
    private array $rules = [];
    /** @var array<string, Relation> */
    private array $relations = [];
    /** @var array<string, UploadField> */
    private array $uploads = [];
    /** @var array<string, bool> */
    private array $operations = [
        'list' => true, 'read' => true, 'create' => true, 'update' => true, 'delete' => true, 'clone' => false, 'export' => true,
    ];
    private Conditions $where;
    /** @var list<array{0: string, 1: string}> */
    private array $orderBy = [];
    private ?int $perPage = null;
    private ?int $maxLimit = null;
    /** @var array<string, list<string>>|null */
    private ?array $permissions = null;

    // Code-only features (not serializable)
    /** @var array<string, list<callable>> */
    private array $callbacks = [];
    /** @var array<string, callable> */
    private array $columnCallbacks = [];
    /** @var list<callable> */
    private array $scopes = [];
    /** @var array<string, array{callback: callable, permission: string}> */
    private array $actions = [];

    private string $source = 'code';

    public function __construct(string $name = '', ?string $table = null)
    {
        $this->where = new Conditions();
        $this->name = $name;
        if ($table !== null || $name !== '') {
            $this->setTable($table ?? $name);
        }
    }

    // ------------------------------------------------------------------
    // Basic configuration
    // ------------------------------------------------------------------

    public function setTable(string $table): self
    {
        self::assertIdentifier($table, 'table');
        $this->table = $table;
        if ($this->name === '') {
            $this->name = $table;
        }

        return $this;
    }

    /**
     * Change the endpoint name (defaults to the table name).
     */
    public function setName(string $name): self
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $name)) {
            throw new ConfigurationException('Invalid resource name "' . $name . '".');
        }
        $this->name = $name;

        return $this;
    }

    /**
     * Use a non-default database connection.
     */
    public function setConnection(?string $connection): self
    {
        $this->connection = $connection === '' ? null : $connection;

        return $this;
    }

    public function setSubject(string $subject, ?string $plural = null): self
    {
        $this->subject = $subject;
        $this->subjectPlural = $plural;

        return $this;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Set the primary key of this table (when it cannot be detected, e.g. views),
     * or of a related table when $table is given (Grocery CRUD set_primary_key).
     */
    public function setPrimaryKey(string $field, ?string $table = null): self
    {
        self::assertIdentifier($field, 'primary key');
        if ($table === null || $table === $this->table) {
            $this->primaryKey = $field;
        } else {
            self::assertIdentifier($table, 'table');
            $this->primaryKeys[$table] = $field;
        }

        return $this;
    }

    // ------------------------------------------------------------------
    // Columns & fields
    // ------------------------------------------------------------------

    /**
     * Columns returned by the list endpoint.
     */
    public function columns(string|array ...$columns): self
    {
        $this->columns = self::flatten($columns);

        return $this;
    }

    public function unsetColumns(string|array ...$columns): self
    {
        $this->unsetColumns = array_values(array_unique([...$this->unsetColumns, ...self::flatten($columns)]));

        return $this;
    }

    /**
     * Columns returned when reading a single record.
     */
    public function setReadFields(string|array ...$fields): self
    {
        $this->readFields = self::flatten($fields);

        return $this;
    }

    public function unsetReadFields(string|array ...$fields): self
    {
        $this->unsetReadFields = array_values(array_unique([...$this->unsetReadFields, ...self::flatten($fields)]));

        return $this;
    }

    /**
     * Writable fields for both create and update.
     */
    public function fields(string|array ...$fields): self
    {
        $this->fields = self::flatten($fields);

        return $this;
    }

    public function addFields(string|array ...$fields): self
    {
        $this->addFields = self::flatten($fields);

        return $this;
    }

    public function editFields(string|array ...$fields): self
    {
        $this->editFields = self::flatten($fields);

        return $this;
    }

    public function unsetFields(string|array ...$fields): self
    {
        $this->unsetFields = array_values(array_unique([...$this->unsetFields, ...self::flatten($fields)]));

        return $this;
    }

    public function unsetAddFields(string|array ...$fields): self
    {
        $this->unsetAddFields = array_values(array_unique([...$this->unsetAddFields, ...self::flatten($fields)]));

        return $this;
    }

    public function unsetEditFields(string|array ...$fields): self
    {
        $this->unsetEditFields = array_values(array_unique([...$this->unsetEditFields, ...self::flatten($fields)]));

        return $this;
    }

    public function requiredFields(string|array ...$fields): self
    {
        $this->requiredFields = array_values(array_unique([...$this->requiredFields, ...self::flatten($fields)]));

        return $this;
    }

    public function uniqueFields(string|array ...$fields): self
    {
        $this->uniqueFields = array_values(array_unique([...$this->uniqueFields, ...self::flatten($fields)]));

        return $this;
    }

    /**
     * Columns used by the "q" full text search parameter.
     */
    public function setSearchable(string|array ...$columns): self
    {
        $this->searchable = self::flatten($columns);

        return $this;
    }

    /**
     * Human readable label of a field. Accepts an array of field => label.
     */
    public function displayAs(string|array $field, ?string $label = null): self
    {
        $map = is_array($field) ? $field : [$field => (string) $label];
        foreach ($map as $f => $l) {
            $this->displayAs[(string) $f] = (string) $l;
        }

        return $this;
    }

    public function fieldType(string $field, string $type, mixed $extras = null): self
    {
        $type = strtolower($type);
        if (! in_array($type, self::FIELD_TYPES, true)) {
            throw new ConfigurationException('Unknown field type "' . $type . '" for field "' . $field . '". Supported: ' . implode(', ', self::FIELD_TYPES));
        }
        $this->fieldTypes[$field] = ['type' => $type, 'extras' => $extras];

        return $this;
    }

    public function changeFieldType(string $field, string $type, mixed $extras = null): self
    {
        return $this->fieldType($field, $type, $extras);
    }

    /**
     * Validation rules using CodeIgniter syntax ("required|max_length[50]|valid_email").
     *
     *   setRules('email', 'required|valid_email')
     *   setRules('email', 'E-mail', 'required|valid_email')   (CodeIgniter / Grocery CRUD order)
     *   setRules(['email' => 'required|valid_email'])
     */
    public function setRules(string|array $field, ?string $labelOrRules = null, ?string $rules = null): self
    {
        if (is_array($field)) {
            foreach ($field as $f => $r) {
                $this->setRules((string) $f, (string) $r);
            }

            return $this;
        }
        $this->rules[$field] = $rules === null
            ? ['rules' => (string) $labelOrRules, 'label' => null]
            : ['rules' => $rules, 'label' => $labelOrRules];

        return $this;
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    /**
     * belongs_to relation (Grocery CRUD set_relation).
     *
     * @param string $field Local foreign key column.
     * @param string $relatedTable Referenced table.
     * @param string $titleField Title column or template "{lastName} {firstName}".
     * @param array<string, mixed>|Conditions|string|null $where Conditions for the related rows (a raw SQL string is accepted in code only).
     * @param string|null $orderBy "column" or "column desc"
     * @param string|null $alias Relation name used for embedding (defaults to the related table name).
     * @param string|null $ownerKey Referenced column (defaults to the primary key of the related table).
     */
    public function setRelation(
        string $field,
        string $relatedTable,
        string $titleField,
        array|Conditions|string|null $where = null,
        ?string $orderBy = null,
        ?string $alias = null,
        ?string $ownerKey = null,
    ): self {
        [$conditions, $raw] = self::normalizeWhere($where);
        $relation = new Relation(
            type: Relation::BELONGS_TO,
            name: $alias ?? $this->defaultRelationName($relatedTable, $field),
            table: $relatedTable,
            field: $field,
            ownerKey: $ownerKey ?? ($this->primaryKeys[$relatedTable] ?? null),
            titleField: $titleField,
            where: $conditions,
            rawWhere: $raw,
            orderBy: Relation::parseOrderBy($orderBy),
        );

        return $this->addRelation($relation);
    }

    /**
     * many_to_many relation (Grocery CRUD set_relation_n_n). The relation name is also the
     * body key used to write the list of related ids.
     */
    public function setRelationNtoN(
        string $fieldName,
        string $junctionTable,
        string $selectionTable,
        string $primaryKeyAliasToThisTable,
        string $primaryKeyAliasToSelectionTable,
        string $titleFieldSelectionTable,
        ?string $priorityFieldJunctionTable = null,
        array|Conditions|string|null $where = null,
    ): self {
        [$conditions, $raw] = self::normalizeWhere($where);

        return $this->addRelation(new Relation(
            type: Relation::MANY_TO_MANY,
            name: $fieldName,
            table: $selectionTable,
            ownerKey: $this->primaryKeys[$selectionTable] ?? null,
            junctionTable: $junctionTable,
            junctionThisKey: $primaryKeyAliasToThisTable,
            junctionOtherKey: $primaryKeyAliasToSelectionTable,
            titleField: $titleFieldSelectionTable,
            priorityField: $priorityFieldJunctionTable,
            where: $conditions,
            rawWhere: $raw,
        ));
    }

    /**
     * has_many relation: rows of $childTable whose $foreignKey references this table.
     */
    public function setRelationHasMany(
        string $name,
        string $childTable,
        string $foreignKey,
        ?string $titleField = null,
        array|Conditions|string|null $where = null,
        ?string $orderBy = null,
        ?string $localKey = null,
    ): self {
        [$conditions, $raw] = self::normalizeWhere($where);

        return $this->addRelation(new Relation(
            type: Relation::HAS_MANY,
            name: $name,
            table: $childTable,
            foreignKey: $foreignKey,
            ownerKey: $localKey,
            titleField: $titleField,
            where: $conditions,
            rawWhere: $raw,
            orderBy: Relation::parseOrderBy($orderBy),
        ));
    }

    public function addRelation(Relation $relation): self
    {
        $this->relations[$relation->name] = $relation;

        return $this;
    }

    public function removeRelation(string $name): self
    {
        unset($this->relations[$name]);

        return $this;
    }

    // ------------------------------------------------------------------
    // Uploads
    // ------------------------------------------------------------------

    /**
     * Grocery CRUD set_field_upload. $uploadDir is relative to the configured upload path
     * (outside the web root by default) unless it is absolute.
     */
    public function setFieldUpload(string $field, string $uploadDir = '', string $allowedFileTypes = '', ?string $maxSize = null): self
    {
        $this->uploads[$field] = new UploadField($field, $uploadDir, $allowedFileTypes !== '' ? $allowedFileTypes : null, $maxSize);

        return $this;
    }

    // ------------------------------------------------------------------
    // Operations (Grocery CRUD unset_*)
    // ------------------------------------------------------------------

    public function unsetAdd(): self
    {
        return $this->setOperation('create', false);
    }

    public function unsetEdit(): self
    {
        return $this->setOperation('update', false);
    }

    public function unsetDelete(): self
    {
        return $this->setOperation('delete', false);
    }

    public function unsetRead(): self
    {
        return $this->setOperation('read', false);
    }

    public function unsetList(): self
    {
        return $this->setOperation('list', false);
    }

    public function unsetExport(): self
    {
        return $this->setOperation('export', false);
    }

    /**
     * No-op kept for Grocery CRUD compatibility (printing is a client concern).
     */
    public function unsetPrint(): self
    {
        return $this;
    }

    public function unsetClone(): self
    {
        return $this->setOperation('clone', false);
    }

    public function setClone(): self
    {
        return $this->setOperation('clone', true);
    }

    /**
     * Make the resource read only (Grocery CRUD unset_operations).
     */
    public function unsetOperations(): self
    {
        foreach (['create', 'update', 'delete', 'clone', 'export'] as $operation) {
            $this->operations[$operation] = false;
        }

        return $this;
    }

    public function setOperation(string $operation, bool $enabled): self
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new ConfigurationException('Unknown operation "' . $operation . '".');
        }
        $this->operations[$operation] = $enabled;

        return $this;
    }

    // ------------------------------------------------------------------
    // Query defaults (Grocery CRUD where / like / order_by / limit)
    // ------------------------------------------------------------------

    /**
     * A condition applied to every query of this resource (reads, updates and deletes).
     */
    public function where(string|array $key, mixed $operatorOrValue = null, mixed $value = null): self
    {
        func_num_args() >= 3 ? $this->where->where($key, $operatorOrValue, $value) : $this->where->where($key, $operatorOrValue);

        return $this;
    }

    public function orWhere(string|array $key, mixed $operatorOrValue = null, mixed $value = null): self
    {
        func_num_args() >= 3 ? $this->where->orWhere($key, $operatorOrValue, $value) : $this->where->orWhere($key, $operatorOrValue);

        return $this;
    }

    public function like(string $field, string $match = '', string $side = 'both'): self
    {
        $this->where->like($field, $match, $side);

        return $this;
    }

    public function orLike(string $field, string $match = '', string $side = 'both'): self
    {
        $this->where->orLike($field, $match, $side);

        return $this;
    }

    public function orderBy(string $field, string $direction = 'asc'): self
    {
        self::assertIdentifier($field, 'order by column');
        $this->orderBy[] = [$field, strtolower($direction) === 'desc' ? 'desc' : 'asc'];

        return $this;
    }

    /**
     * Default page size of the list endpoint.
     */
    public function limit(int $limit): self
    {
        $this->perPage = max(1, $limit);

        return $this;
    }

    public function setMaxLimit(int $limit): self
    {
        $this->maxLimit = max(1, $limit);

        return $this;
    }

    // ------------------------------------------------------------------
    // Security
    // ------------------------------------------------------------------

    /**
     * Allowed operations per role. Use "*" for any role (including "anon").
     *
     *   ->setPermissions('anon', ['select'])
     *   ->setPermissions(['anon' => ['select'], 'editor' => ['select', 'insert', 'update']])
     */
    public function setPermissions(string|array $role, ?array $operations = null): self
    {
        $map = is_array($role) ? $role : [$role => $operations ?? []];
        $this->permissions ??= [];
        foreach ($map as $r => $ops) {
            $ops = array_values(array_unique(array_map('strtolower', (array) $ops)));
            foreach ($ops as $op) {
                if (! in_array($op, self::PERMISSIONS, true)) {
                    throw new ConfigurationException('Unknown permission "' . $op . '". Use: ' . implode(', ', self::PERMISSIONS));
                }
            }
            $this->permissions[(string) $r] = $ops;
        }

        return $this;
    }

    /**
     * Allow every operation to everybody. Use when the route is already protected by
     * your framework (e.g. a CodeIgniter filter).
     */
    public function public(): self
    {
        return $this->setPermissions('*', self::PERMISSIONS);
    }

    /**
     * Row level security: the callback receives a Conditions object (and the auth context)
     * and may add conditions that are applied to every read, update and delete.
     * The service_role bypasses scopes (like Supabase's row level security).
     *
     *   ->scope(fn (Conditions $q, AuthContext $auth) => $q->where('owner_id', $auth->claim('sub')))
     *
     * @param callable(Conditions, \GroceryRestable\Auth\AuthContext): mixed $callback
     */
    public function scope(callable $callback): self
    {
        $this->scopes[] = $callback;

        return $this;
    }

    // ------------------------------------------------------------------
    // Callbacks (Grocery CRUD callback_*)
    // ------------------------------------------------------------------

    /** @param callable(array $data, AuthContext $auth): (array|false) $callback */
    public function callbackBeforeInsert(callable $callback): self
    {
        return $this->on('before_insert', $callback);
    }

    /** @param callable(array $data, int|string $id, AuthContext $auth): mixed $callback */
    public function callbackAfterInsert(callable $callback): self
    {
        return $this->on('after_insert', $callback);
    }

    /** Replace the insert. @param callable(array $data, AuthContext $auth): (int|string) $callback Returns the new primary key. */
    public function callbackInsert(callable $callback): self
    {
        return $this->on('insert', $callback, true);
    }

    /** @param callable(array $data, int|string $id, AuthContext $auth): (array|false) $callback */
    public function callbackBeforeUpdate(callable $callback): self
    {
        return $this->on('before_update', $callback);
    }

    /** @param callable(array $data, int|string $id, AuthContext $auth): mixed $callback */
    public function callbackAfterUpdate(callable $callback): self
    {
        return $this->on('after_update', $callback);
    }

    /** Replace the update. @param callable(array $data, int|string $id, AuthContext $auth): mixed $callback */
    public function callbackUpdate(callable $callback): self
    {
        return $this->on('update', $callback, true);
    }

    /** @param callable(int|string $id, AuthContext $auth): bool $callback */
    public function callbackBeforeDelete(callable $callback): self
    {
        return $this->on('before_delete', $callback);
    }

    /** @param callable(int|string $id, AuthContext $auth): mixed $callback */
    public function callbackAfterDelete(callable $callback): self
    {
        return $this->on('after_delete', $callback);
    }

    /** Replace the delete (e.g. soft delete). @param callable(int|string $id, AuthContext $auth): mixed $callback */
    public function callbackDelete(callable $callback): self
    {
        return $this->on('delete', $callback, true);
    }

    /** @param callable(array $data, int|string $sourceId, AuthContext $auth): (array|false) $callback */
    public function callbackBeforeClone(callable $callback): self
    {
        return $this->on('before_clone', $callback);
    }

    /** @param callable(array $data, int|string $newId, AuthContext $auth): mixed $callback */
    public function callbackAfterClone(callable $callback): self
    {
        return $this->on('after_clone', $callback);
    }

    /**
     * Transform a column value in API output.
     *
     * @param callable(mixed $value, array $row): mixed $callback
     */
    public function callbackColumn(string $column, callable $callback): self
    {
        $this->columnCallbacks[$column] = $callback;

        return $this;
    }

    /**
     * Called before a file is stored. Return false or an error string to reject it.
     *
     * @param callable(\GroceryRestable\Http\UploadedFileInterface $file, UploadField $field, AuthContext $auth): (bool|string|null) $callback
     */
    public function callbackBeforeUpload(callable $callback): self
    {
        return $this->on('before_upload', $callback);
    }

    /**
     * Called after a file is stored.
     *
     * @param callable(array{file_name: string, path: string, size: int, mime: string, original_name: string} $info, UploadField $field, AuthContext $auth): mixed $callback
     */
    public function callbackAfterUpload(callable $callback): self
    {
        return $this->on('after_upload', $callback);
    }

    /**
     * Replace the storage of uploaded files. Must return the value stored in the column.
     *
     * @param callable(\GroceryRestable\Http\UploadedFileInterface $file, UploadField $field, AuthContext $auth): string $callback
     */
    public function callbackUpload(callable $callback): self
    {
        return $this->on('upload', $callback, true);
    }

    /**
     * Custom endpoint: POST {resource}/{id}/actions/{name}
     *
     * @param callable(int|string $id, array $payload, \GroceryRestable\Service\ActionContext $context): mixed $callback
     * @param string $permission Permission required to run the action (select|insert|update|delete).
     */
    public function addAction(string $name, callable $callback, string $permission = 'update'): self
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $name)) {
            throw new ConfigurationException('Invalid action name "' . $name . '".');
        }
        if (! in_array($permission, self::PERMISSIONS, true)) {
            throw new ConfigurationException('Unknown permission "' . $permission . '".');
        }
        $this->actions[$name] = ['callback' => $callback, 'permission' => $permission];

        return $this;
    }

    // ------------------------------------------------------------------
    // Getters
    // ------------------------------------------------------------------

    public function getName(): string
    {
        return $this->name;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getConnection(): ?string
    {
        return $this->connection;
    }

    public function getSubject(): string
    {
        return $this->subject ?? ucfirst(str_replace(['_', '-'], ' ', $this->name));
    }

    public function getSubjectPlural(): ?string
    {
        return $this->subjectPlural;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPrimaryKeyOverride(?string $table = null): ?string
    {
        return $table === null || $table === $this->table ? $this->primaryKey : ($this->primaryKeys[$table] ?? null);
    }

    /**
     * @return array<string, Relation>
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    public function getRelation(string $name): ?Relation
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * belongs_to relation configured on a column.
     */
    public function getRelationByField(string $field): ?Relation
    {
        foreach ($this->relations as $relation) {
            if ($relation->isBelongsTo() && $relation->field === $field) {
                return $relation;
            }
        }

        return null;
    }

    /**
     * @return array<string, UploadField>
     */
    public function getUploads(): array
    {
        return $this->uploads;
    }

    public function getUpload(string $field): ?UploadField
    {
        return $this->uploads[$field] ?? null;
    }

    public function isEnabled(string $operation): bool
    {
        return $this->operations[$operation] ?? false;
    }

    /**
     * @return array<string, bool>
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    public function getWhere(): Conditions
    {
        return $this->where;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function getOrderBy(): array
    {
        return $this->orderBy;
    }

    public function getPerPage(): ?int
    {
        return $this->perPage;
    }

    public function getMaxLimit(): ?int
    {
        return $this->maxLimit;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public function getPermissions(): ?array
    {
        return $this->permissions;
    }

    /**
     * @return list<string>
     */
    public function getRequiredFields(): array
    {
        return $this->requiredFields;
    }

    /**
     * @return list<string>
     */
    public function getUniqueFields(): array
    {
        return $this->uniqueFields;
    }

    /**
     * @return array<string, array{rules: string, label: string|null}>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * @return array<string, string>
     */
    public function getDisplayAs(): array
    {
        return $this->displayAs;
    }

    public function label(string $field): string
    {
        return $this->displayAs[$field] ?? ucfirst((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', str_replace(['_', '-'], ' ', $field)));
    }

    /**
     * @return array{type: string, extras: mixed}|null
     */
    public function getFieldType(string $field): ?array
    {
        return $this->fieldTypes[$field] ?? null;
    }

    /**
     * @return array<string, array{type: string, extras: mixed}>
     */
    public function getFieldTypes(): array
    {
        return $this->fieldTypes;
    }

    /**
     * @return list<callable>
     */
    public function getCallbacks(string $event): array
    {
        return $this->callbacks[$event] ?? [];
    }

    public function getColumnCallback(string $column): ?callable
    {
        return $this->columnCallbacks[$column] ?? null;
    }

    /**
     * @return list<callable>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * @return array<string, array{callback: callable, permission: string}>
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    // ------------------------------------------------------------------
    // Column resolution against the real table
    // ------------------------------------------------------------------

    /**
     * Primary key column of the resource table.
     */
    public function primaryKey(Table $table): ?string
    {
        return $this->primaryKey ?? $table->singlePrimaryKey();
    }

    /**
     * Whether a column is never exposed in output.
     */
    public function isHiddenColumn(string $column): bool
    {
        $type = $this->fieldTypes[$column]['type'] ?? null;

        return in_array($type, ['hidden', 'invisible', 'password'], true);
    }

    /**
     * Columns returned by the list endpoint.
     *
     * @return list<string>
     */
    public function listColumns(Table $table): array
    {
        $base = $this->columns !== [] ? array_values(array_filter($this->columns, $table->hasColumn(...))) : $table->columnNames();

        return $this->visible($base, $this->unsetColumns);
    }

    /**
     * Relation names listed in columns() — embedded by default in list output.
     *
     * @return list<string>
     */
    public function defaultListEmbeds(): array
    {
        return array_values(array_filter($this->columns, fn (string $c): bool => isset($this->relations[$c])));
    }

    /**
     * Relation names listed in setReadFields() — embedded by default in single record output.
     *
     * @return list<string>
     */
    public function defaultReadEmbeds(): array
    {
        return array_values(array_filter($this->readFields, fn (string $c): bool => isset($this->relations[$c])));
    }

    /**
     * Columns returned when reading a single record.
     *
     * @return list<string>
     */
    public function readColumns(Table $table): array
    {
        $base = $this->readFields !== [] ? array_values(array_filter($this->readFields, $table->hasColumn(...))) : $table->columnNames();

        return $this->visible($base, $this->unsetReadFields);
    }

    /**
     * Columns that may be used for filtering, ordering and searching.
     *
     * @return list<string>
     */
    public function queryableColumns(Table $table): array
    {
        return array_values(array_unique([...$this->listColumns($table), ...$this->readColumns($table)]));
    }

    /**
     * @return list<string>
     */
    public function searchableColumns(Table $table): array
    {
        $queryable = $this->queryableColumns($table);
        if ($this->searchable !== []) {
            return array_values(array_intersect($this->searchable, $queryable));
        }

        return array_values(array_filter(
            $this->listColumns($table),
            static fn (string $c): bool => in_array($table->columns[$c]->type, ['string', 'text', 'enum', 'uuid'], true),
        ));
    }

    /**
     * Fields a client may send on create ("create") or update ("update").
     * Includes many_to_many relation names.
     *
     * @return list<string>
     */
    public function writableFields(Table $table, string $mode): array
    {
        $specific = $mode === 'create' ? $this->addFields : $this->editFields;
        $explicit = $specific !== [] ? $specific : $this->fields;
        $unset = [...$this->unsetFields, ...($mode === 'create' ? $this->unsetAddFields : $this->unsetEditFields)];
        $pk = $this->primaryKey($table);

        if ($explicit !== []) {
            $candidates = $explicit;
        } else {
            $candidates = [];
            foreach ($table->columns as $name => $column) {
                if ($column->generated) {
                    continue;
                }
                if ($name === $pk && ($mode === 'update' || $column->autoIncrement)) {
                    continue;
                }
                $candidates[] = $name;
            }
            foreach ($this->relations as $relation) {
                if ($relation->isManyToMany()) {
                    $candidates[] = $relation->name;
                }
            }
        }

        $result = [];
        foreach ($candidates as $field) {
            if (in_array($field, $unset, true)) {
                continue;
            }
            $type = $this->fieldTypes[$field]['type'] ?? null;
            if (in_array($type, ['readonly', 'invisible', 'hidden'], true)) {
                continue;
            }
            $isRelation = isset($this->relations[$field]) && $this->relations[$field]->isManyToMany();
            if (! $isRelation && (! $table->hasColumn($field) || $table->columns[$field]->generated)) {
                continue;
            }
            $result[] = $field;
        }

        return array_values(array_unique($result));
    }

    /**
     * Values forced on write by hidden fields with "extras" (Grocery CRUD field_type hidden).
     *
     * @return array<string, mixed>
     */
    public function forcedValues(): array
    {
        $values = [];
        foreach ($this->fieldTypes as $field => $definition) {
            if ($definition['type'] === 'hidden' && $definition['extras'] !== null) {
                $values[$field] = $definition['extras'];
            }
        }

        return $values;
    }

    /**
     * Validate the definition against the real table. Returns a list of problems.
     *
     * @return list<string>
     */
    public function problems(Table $table): array
    {
        $problems = [];
        $check = function (array $list, string $what) use ($table, &$problems): void {
            foreach ($list as $column) {
                if (! $table->hasColumn($column) && ! isset($this->relations[$column])) {
                    $problems[] = sprintf('%s references unknown column "%s".', $what, $column);
                }
            }
        };
        $check($this->columns, 'columns()');
        $check($this->readFields, 'setReadFields()');
        $check($this->fields, 'fields()');
        $check($this->addFields, 'addFields()');
        $check($this->editFields, 'editFields()');
        $check($this->requiredFields, 'requiredFields()');
        $check($this->uniqueFields, 'uniqueFields()');
        $check(array_keys($this->uploads), 'setFieldUpload()');
        $check($this->where->columns(), 'where()');
        $check(array_column($this->orderBy, 0), 'orderBy()');

        foreach ($this->relations as $relation) {
            if ($relation->isBelongsTo() && ! $table->hasColumn((string) $relation->field)) {
                $problems[] = sprintf('Relation "%s" uses unknown column "%s".', $relation->name, $relation->field);
            }
            if ($table->hasColumn($relation->name)) {
                $problems[] = sprintf('Relation name "%s" collides with a column name.', $relation->name);
            }
        }
        if ($this->primaryKey($table) === null) {
            $problems[] = 'The table has no single-column primary key: only the list endpoint is available. Use setPrimaryKey().';
        }

        return $problems;
    }

    // ------------------------------------------------------------------
    // Serialization (GUI storage / code export)
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'connection' => $this->connection,
            'table' => $this->table,
            'primaryKey' => $this->primaryKey,
            'primaryKeys' => $this->primaryKeys,
            'subject' => $this->subject,
            'subjectPlural' => $this->subjectPlural,
            'description' => $this->description,
            'columns' => $this->columns,
            'unsetColumns' => $this->unsetColumns,
            'readFields' => $this->readFields,
            'unsetReadFields' => $this->unsetReadFields,
            'fields' => $this->fields,
            'addFields' => $this->addFields,
            'editFields' => $this->editFields,
            'unsetFields' => $this->unsetFields,
            'unsetAddFields' => $this->unsetAddFields,
            'unsetEditFields' => $this->unsetEditFields,
            'requiredFields' => $this->requiredFields,
            'uniqueFields' => $this->uniqueFields,
            'searchable' => $this->searchable,
            'displayAs' => $this->displayAs,
            'fieldTypes' => $this->fieldTypes,
            'rules' => $this->rules,
            'relations' => array_map(static fn (Relation $r): array => $r->toArray(), array_values($this->relations)),
            'uploads' => array_map(static fn (UploadField $u): array => $u->toArray(), array_values($this->uploads)),
            'operations' => $this->operations,
            'where' => $this->where->toArray(),
            'orderBy' => $this->orderBy,
            'perPage' => $this->perPage,
            'maxLimit' => $this->maxLimit,
            'permissions' => $this->permissions,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $resource = new self((string) ($data['name'] ?? ''), (string) ($data['table'] ?? $data['name'] ?? ''));
        $resource->setConnection(isset($data['connection']) ? (string) $data['connection'] : null);
        if (! empty($data['primaryKey'])) {
            $resource->setPrimaryKey((string) $data['primaryKey']);
        }
        foreach ((array) ($data['primaryKeys'] ?? []) as $table => $pk) {
            $resource->setPrimaryKey((string) $pk, (string) $table);
        }
        if (! empty($data['subject'])) {
            $resource->setSubject((string) $data['subject'], ! empty($data['subjectPlural']) ? (string) $data['subjectPlural'] : null);
        }
        $resource->description = (string) ($data['description'] ?? '');

        foreach (['columns', 'unsetColumns', 'readFields', 'unsetReadFields', 'fields', 'addFields', 'editFields',
            'unsetFields', 'unsetAddFields', 'unsetEditFields', 'requiredFields', 'uniqueFields', 'searchable'] as $list) {
            $values = array_values(array_filter(array_map('strval', (array) ($data[$list] ?? [])), static fn (string $v): bool => $v !== ''));
            foreach ($values as $value) {
                self::assertIdentifier($value, $list);
            }
            $resource->{$list} = $values;
        }

        $resource->displayAs((array) ($data['displayAs'] ?? []));
        foreach ((array) ($data['fieldTypes'] ?? []) as $field => $definition) {
            $resource->fieldType((string) $field, (string) ($definition['type'] ?? 'string'), $definition['extras'] ?? null);
        }
        foreach ((array) ($data['rules'] ?? []) as $field => $definition) {
            if (is_string($definition)) {
                $resource->setRules((string) $field, $definition);
            } elseif (($definition['rules'] ?? '') !== '') {
                $resource->setRules((string) $field, $definition['label'] ?? null, (string) $definition['rules']);
            }
        }
        foreach ((array) ($data['relations'] ?? []) as $relation) {
            $resource->addRelation(Relation::fromArray((array) $relation));
        }
        foreach ((array) ($data['uploads'] ?? []) as $upload) {
            $upload = UploadField::fromArray((array) $upload);
            $resource->uploads[$upload->field] = $upload;
        }
        foreach ((array) ($data['operations'] ?? []) as $operation => $enabled) {
            $resource->setOperation((string) $operation, (bool) $enabled);
        }
        $resource->where = Conditions::fromArray((array) ($data['where'] ?? []));
        foreach ((array) ($data['orderBy'] ?? []) as $order) {
            if (is_array($order) && isset($order[0])) {
                $resource->orderBy((string) $order[0], (string) ($order[1] ?? 'asc'));
            }
        }
        if (! empty($data['perPage'])) {
            $resource->limit((int) $data['perPage']);
        }
        if (! empty($data['maxLimit'])) {
            $resource->setMaxLimit((int) $data['maxLimit']);
        }
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $resource->permissions = [];
            $resource->setPermissions($data['permissions']);
        }

        return $resource;
    }

    /**
     * Grocery CRUD snake_case compatibility (set_relation, unset_delete, callback_after_insert, ...).
     *
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $target = self::ALIASES[$method] ?? lcfirst(str_replace('_', '', ucwords($method, '_')));
        if ($target !== $method && method_exists($this, $target)) {
            return $this->{$target}(...$arguments);
        }

        throw new BadMethodCallException('Call to undefined method ' . self::class . '::' . $method . '()');
    }

    public function __clone()
    {
        $this->where = clone $this->where;
    }

    // ------------------------------------------------------------------

    private function on(string $event, callable $callback, bool $replace = false): self
    {
        if ($replace) {
            $this->callbacks[$event] = [$callback];
        } else {
            $this->callbacks[$event][] = $callback;
        }

        return $this;
    }

    /**
     * @param list<string> $base
     * @param list<string> $unset
     * @return list<string>
     */
    private function visible(array $base, array $unset): array
    {
        return array_values(array_filter(
            $base,
            fn (string $c): bool => ! in_array($c, $unset, true) && ! $this->isHiddenColumn($c),
        ));
    }

    private function defaultRelationName(string $relatedTable, string $field): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $relatedTable);
        if (isset($this->relations[$name]) || $name === $field) {
            $name = $field . '_' . $name;
        }

        return $name;
    }

    /**
     * @return array{0: Conditions|null, 1: string|null}
     */
    private static function normalizeWhere(array|Conditions|string|null $where): array
    {
        return match (true) {
            $where === null, $where === '', $where === [] => [null, null],
            $where instanceof Conditions => [$where, null],
            is_array($where) => [Conditions::fromArray($where), null],
            default => [null, $where],
        };
    }

    /**
     * @param array<int, string|array<int, string>> $values
     * @return list<string>
     */
    private static function flatten(array $values): array
    {
        $result = [];
        array_walk_recursive($values, static function (mixed $value) use (&$result): void {
            $value = trim((string) $value);
            if ($value !== '') {
                $result[] = $value;
            }
        });

        return array_values(array_unique($result));
    }

    private static function assertIdentifier(string $value, string $what): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $value)) {
            throw new ConfigurationException(sprintf('Invalid %s "%s".', $what, $value));
        }
    }
}

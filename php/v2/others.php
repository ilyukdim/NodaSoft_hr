<?php

namespace NW\WebService\References\Operations\Notification;

use Exception;

class Contractor {
    public const TYPE_CUSTOMER = 0;
    public const TYPE_SELLER = 1;
    public const TYPE_EMPLOYEE = 2;
    public int $id;
    public int $type;
    public string $name;

    public function __construct(int $resellerId) {
        $this->id = $resellerId;
        $this->type = self::TYPE_CUSTOMER;
        $this->name = '';
    }
    
    public static function getById(int $resellerId): ?static {
        return new static($resellerId); // fakes the getById method
    }

    public function getFullName(): string {
        return ($this->name ? $this->name . ' ' : '') . $this->id;
    }
}

class Seller extends Contractor {
    public function __construct(int $resellerId) {
        parent::__construct($resellerId);
        $this->type = self::TYPE_SELLER;
    }
}

class Employee extends Contractor {
    public function __construct(int $resellerId) {
        parent::__construct($resellerId);
        $this->type = self::TYPE_EMPLOYEE;
    }
}

class Status {
    public const COMPLETED = 0;
    public const PENDING = 1;
    public const REJECTED = 2;
    public int $id;
    public string $name;

    public static function getName(int $id): ?string {
        $a = [
            self::COMPLETED => 'Completed',
            self::PENDING => 'Pending',
            self::REJECTED => 'Rejected',
        ];

        return $a[$id] ?? null;
    }
}

abstract class ReferencesOperation {
    /**
     * @return array{
     * notificationEmployeeByEmail:bool,
     * notificationClientByEmail:bool,
     * notificationClientBySms: array{isSent:bool, error: string}
     * }
     */
    abstract public function doOperation(): array;

    public function getRequest($pName): mixed {
        return $_REQUEST[$pName] ?? null;
    }
}

function getResellerEmailFrom(): string {
    return 'contractor@example.com';
}

function getEmailsByPermit(int $resellerId, string $event): array {
    // fakes the method
    return ['someemeil@example.com', 'someemeil2@example.com'];
}


class NotificationEvents {
    public const CHANGE_RETURN_STATUS = 'changeReturnStatus';
    public const NEW_RETURN_STATUS = 'newReturnStatus';
}

class Validation {
    /**
     * @param array<string,array{req: ?bool,type: ?string, match: ?string}> $fieldRules
     * @param array<string,array{mixed}> $fieldData
     */
    public function __construct(private readonly array $fieldRules, private array $fieldData) {
    }
    
    /**
     * @throws Exception
     */
    private function validateField(string $fieldName): void {
        try {
            $arrKeys = explode('.', $fieldName);
            $value = &$this->fieldData;
            foreach ($arrKeys as $key) {
                if (isset($value[$key])) {
                    $value = &$value[$key];
                } else {
                    unset($value);
                    break;
                }
            }
            $field = $this->fieldRules[$fieldName];
            if (!isset($value)) {
                if ($field['req']) {
                    throw new Exception("Field $fieldName not found.");
                }
            } else {
                $value = $this->castValue($fieldName, $value);
                $this->matchValue($fieldName, $value);
            }
        } finally {
            unset($value);
        }
    }
    
    /**
     * @return array<mixed>
     * @throws Exception
     */
    public function validate(): array {
        if (!is_array($this->fieldRules)) {
            throw new Exception('Field rules is not array');
        }
        if (!is_array($this->fieldData)) {
            throw new Exception('Field data is not array');
        }
        $fields = $this->fieldRules;
        foreach ($fields as $key => $field) {
            $this->validateField($key);
        }
        
        return $this->fieldData;
    }
    
    /**
     * @throws Exception
     */
    private function matchValue(string $fieldName, mixed $value): void {
        $rules = $this->fieldRules[$fieldName];
        $type = $rules['type'];
        $match = $rules['match'] ?? '';
    
        if (empty($match) || $type !== 'string') {
            return;
        }
        
        if (!preg_match($match, $value)) {
            throw new Exception("Value field '{$fieldName}' not match '{$match}'");
        }
    }
    
    /**
     * @throws Exception
     */
    private function castValue(string $fieldName, mixed $value): int|string {
        $rules = $this->fieldRules[$fieldName];
        $type = $rules['type'];
        $req = $rules['req'];
        
        if (empty($type)) {
            return $value;
        }
        
        switch ($type) {
            case 'int':
                if (is_numeric($value)) {
                    $value = (int)$value;
                    if (!$req || $value !== 0) {
                        return $value;
                    }
                }
                break;
            case 'string':
                if (is_string($value)) {
                    $value = (string)$value;
                    if (!$req || $value !== '') {
                        return $value;
                    }
                }
                break;
        }
        throw new Exception("Value is not type '{$type}'");
    }
}

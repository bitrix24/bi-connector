<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Guards table and field names arriving with a request before they reach the data source and SQL building.
 *
 * The patterns repeat the portal rules for external dataset names: ExternalDatasetTable::TABLE_NAME_REGEXP
 * and ExternalDatasetFieldTable::FIELD_NAME_REGEXP.
 */
final class NameValidator
{
    // The D modifier keeps `$` at the very end of the subject, so a trailing newline cannot pass the pattern.
    private const TABLE_NAME_PATTERN = '/^[a-z][a-z0-9_]*$/D';
    private const FIELD_NAME_PATTERN = '/^[A-Z][A-Z0-9_]*$/D';

    /**
     * @throws \InvalidArgumentException when the value is not an acceptable table name
     */
    public function validateTableName(mixed $name): string
    {
        return $this->validateName($name, self::TABLE_NAME_PATTERN, 'table');
    }

    /**
     * @throws \InvalidArgumentException when the value is not an acceptable field name
     */
    public function validateFieldName(mixed $name): string
    {
        return $this->validateName($name, self::FIELD_NAME_PATTERN, 'field');
    }

    /**
     * @return list<string>
     *
     * @throws \InvalidArgumentException when the value is not a list of acceptable field names
     */
    public function validateFieldNames(mixed $names): array
    {
        if (!is_array($names)) {
            throw new \InvalidArgumentException(sprintf(
                'A list of field names is required, %s given',
                get_debug_type($names)
            ));
        }

        $validated = [];

        foreach ($names as $name) {
            $validated[] = $this->validateFieldName($name);
        }

        return $validated;
    }

    /**
     * @throws \InvalidArgumentException when the value is not a string matching the pattern
     */
    private function validateName(mixed $name, string $pattern, string $kind): string
    {
        if (!is_string($name) || preg_match($pattern, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid %s name: "%s"',
                $kind,
                is_string($name) ? $name : get_debug_type($name)
            ));
        }

        return $name;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Request;

use yii\base\Model;

final class CloseRequestInput extends Model
{
    public mixed $expertiseRequired = true;
    public mixed $securityRequired = true;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            [['lockVersion'], 'required'],
            ['lockVersion', 'integer', 'min' => 1],
            [['expertiseRequired', 'securityRequired'], 'boolean', 'trueValue' => true, 'falseValue' => false, 'strict' => true, 'skipOnEmpty' => false],
            ['securityRequired', 'validateDependency'],
        ];
    }

    public function validateDependency(string $attribute): void
    {
        if ($this->securityRequired === true && $this->expertiseRequired === false) {
            $this->addError($attribute, 'Контроль СБ возможен только после экспертизы.');
        }
    }
}

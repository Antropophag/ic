<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Request\Command\PublishOpinionCommand;
use yii\base\Model;

final class PublishOpinionRequest extends Model
{
    public mixed $body = null;
    public mixed $lockVersion = null;

    public function rules(): array
    {
        return [
            ['body', 'filter', 'filter' => 'trim'],
            ['body', 'required'],
            ['body', 'string', 'min' => 10, 'max' => 20000],
            ['lockVersion', 'required'],
            ['lockVersion', 'integer', 'min' => 1],
        ];
    }

    public function toCommand(int $requestId, int $actorId): PublishOpinionCommand
    {
        return new PublishOpinionCommand($requestId, $actorId, (string) $this->body, (int) $this->lockVersion);
    }
}

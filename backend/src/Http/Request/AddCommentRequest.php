<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Request\Command\AddCommentCommand;
use yii\base\Model;

final class AddCommentRequest extends Model
{
    public ?string $body = null;

    public function rules(): array
    {
        return [
            ['body', 'trim'],
            ['body', 'required'],
            ['body', 'string', 'max' => 10000],
        ];
    }

    public function toCommand(int $requestId, int $actorId): AddCommentCommand
    {
        return new AddCommentCommand($requestId, $actorId, (string) $this->body);
    }
}

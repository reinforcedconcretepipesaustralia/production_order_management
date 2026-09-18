<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MouldConstraintValidationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'valid' => true,
            'conflicts' => [],
            'changeover' => [
                'preceding_schedule_id' => $this->resource->precedingScheduleId,
                'required_blocks' => $this->resource->requiredBlocks,
            ],
        ];
    }
}

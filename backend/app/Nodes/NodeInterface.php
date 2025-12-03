<?php
namespace App\Nodes;

interface NodeInterface {
    public function __construct(array $config);
    public function execute(array $inputData): array;
    public function getType(): string;
    public function getName(): string;
    public function validate(): bool;
    public function getOutputSchema(): array;
    public function getConfig(): array;
}
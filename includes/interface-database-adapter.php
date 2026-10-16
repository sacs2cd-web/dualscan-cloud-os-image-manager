<?php
namespace Dualscan\CloudOS;
interface DatabaseAdapter {
    public function identity(): array;
    public function exportTo(string $directory, ?callable $progress = null): array;
    public function validateFrom(string $directory, array $metadata): void;
    public function restoreFrom(string $directory, array $metadata): void;
}

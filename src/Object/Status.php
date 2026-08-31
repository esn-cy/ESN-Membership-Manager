<?php

namespace Drupal\esn_membership_manager\Object;

class Status
{
    public string $status;
    public string $category;
    public string $issue;

    public function __construct(
        string $status,
        string $category = '',
        string $issue = ''
    ) {
        $this->status = $status;
        $this->category = $category;
        $this->issue = $issue;
    }

    public function clearIssue(): self
    {
        $this->category = '';
        $this->issue = '';
        return $this;
    }

    public function toString(): string
    {
        if (empty($this->category) && empty($this->issue)) {
            return $this->status;
        }
        return $this->status . ' - ' . $this->category . ' - ' . $this->issue;
    }
}
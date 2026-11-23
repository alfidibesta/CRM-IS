<?php
/**
 * SOP / policy document control: option lists, form validation and code
 * allocation, shared by the router and the library view.
 */
declare(strict_types=1);

const SOP_TYPES    = ['Policy', 'SOP', 'Guideline'];
const SOP_SCOPES   = ['Physical', 'IT', 'Strain', 'General'];
const SOP_STATUSES = ['Draft', 'In Review', 'Active', 'Obsolete'];

/** Longest controlled text the `content` TEXT column holds, in bytes. */
const SOP_CONTENT_MAX = 60000;

/**
 * Validate and normalise a posted document form.
 *
 * @return array{0: array<string, mixed>, 1: string} the row and the first error
 */
function sop_fields(array $post, bool $codeRequired = false): array
{
    $code    = strtoupper(trim((string) ($post['code'] ?? '')));
    $title   = clamp($post['title'] ?? '', 200);
    $content = (string) ($post['content'] ?? '');
    $from    = date_or($post['effective_at'] ?? '', null);
    $review  = date_or($post['review_at'] ?? '', null);
    $status  = in_array($post['status'] ?? '', SOP_STATUSES, true) ? (string) $post['status'] : 'Draft';

    $err = '';
    if ($title === '') {
        $err = 'A document title is required.';
    } elseif ($code === '' && $codeRequired) {
        $err = 'A document code is required.';
    } elseif ($code !== '' && !preg_match('/^[A-Z0-9._-]{1,20}$/', $code)) {
        $err = 'The code may hold letters, digits, dots, dashes and underscores only, up to 20 characters.';
    } elseif (strlen($content) > SOP_CONTENT_MAX) {
        $err = 'The controlled text is too long — keep it under 60,000 bytes.';
    } elseif ($from !== null && $review !== null && strcmp($review, $from) < 0) {
        $err = 'The next review date falls before the effective date; correct one of the two.';
    } elseif ($status === 'Active' && $from === null) {
        $err = 'An Active document needs an effective date.';
    }

    return [[
        'code'         => $code,
        'title'        => $title,
        'type'         => in_array($post['type'] ?? '', SOP_TYPES, true) ? (string) $post['type'] : 'SOP',
        'scope'        => in_array($post['scope'] ?? '', SOP_SCOPES, true) ? (string) $post['scope'] : 'General',
        'version'      => clamp($post['version'] ?? '1.0', 10) ?: '1.0',
        'owner'        => clamp($post['owner'] ?? '', 100),
        'status'       => $status,
        'effective_at' => $from,
        'review_at'    => $review,
        'summary'      => clamp($post['summary'] ?? '', 500),
        'content'      => $content,
    ], $err];
}

/** Scope prefix the register already uses: PHYS-001, IT-001, STRAIN-001, GEN-001. */
function sop_code_prefix(string $scope): string
{
    return ['Physical' => 'PHYS', 'IT' => 'IT', 'Strain' => 'STRAIN'][$scope] ?? 'GEN';
}

/** Next free code for a scope, so a blank code never collides with a row. */
function sop_next_code(PDO $pdo, string $scope): string
{
    $prefix = sop_code_prefix($scope) . '-';
    $st = $pdo->prepare('SELECT code FROM sops WHERE code LIKE ?');
    $st->execute([$prefix . '%']);
    $taken = array_flip($st->fetchAll(PDO::FETCH_COLUMN));

    for ($n = 1; $n < 1000; $n++) {
        $code = $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        if (!isset($taken[$code])) {
            return $code;
        }
    }
    return $prefix . '999';
}

/** True when a document other than $exceptId already owns the code. */
function sop_code_taken(PDO $pdo, string $code, int $exceptId = 0): bool
{
    $st = $pdo->prepare('SELECT id FROM sops WHERE code = ? AND id <> ?');
    $st->execute([$code, $exceptId]);
    return (bool) $st->fetchColumn();
}

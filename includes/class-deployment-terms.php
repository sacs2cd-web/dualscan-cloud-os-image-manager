<?php
declare(strict_types=1);
namespace Dualscan\CloudOS;

/** Versioned deployment agreement. Publisher should obtain local legal review. */
final class DeploymentTerms
{
    public const VERSION = '2026-10-16.2';
    public static function text(): string
    {
        return <<<'TERMS'
Dualscan Cloud OS Image Manager — Deployment Agreement

1. Authorization and intellectual property. You confirm that you are authorized to deploy this image and have the necessary rights to its software, content and data. You must not misuse, infringe, disclose or distribute protected intellectual property, personal information or confidential material without authorization.

2. Restricted application access. This deployment serves the public application only. You must not attempt to bypass access controls, obtain restricted administrative credentials, or access non-public code or data without authorization. To the extent permitted by applicable law and the relevant software licenses, you agree not to reverse engineer, decompile or disassemble proprietary components or defeat their protection.

3. Permanent WordPress administration removal. Pressing DEPLOY INSTANCE replaces this installation, deletes its WordPress administrator accounts, preserves their published content through disabled attribution accounts, invalidates existing sessions and disables WordPress login, wp-admin and management APIs. You will be logged out and returned to the public homepage. There is no web-based unlock or recovery account. Changes require replacement with a newly authored image or a fresh WordPress installation through the deployment operator.

4. Assumption of risk and release. You accept the deployment as provided, acknowledge the risk of service interruption and data loss, and are responsible for retaining a verified image and arranging for the deployment operator to retain its matching server encryption key separately. Loss of that key prevents restoration. To the fullest extent permitted by applicable law, you waive and release claims against the platform operator arising from your voluntary use of the platform, including loss of administrative access, and accept applicable exclusions of warranties and liability. This clause does not waive rights or claims that cannot lawfully be waived, including liability that applicable law prohibits excluding.

5. Preserved rights and scope. Nothing in this agreement limits rights granted by WordPress, the GPL or another applicable open-source license, lawful interoperability or security research rights that cannot be restricted, honest consumer reviews, or non-waivable statutory protections. These terms concern WordPress application access and do not represent that server owners or hosting providers lose their independent infrastructure privileges. The operator grants no rights to third-party intellectual property beyond those held by its licensors.

By checking the acknowledgments and pressing DEPLOY INSTANCE, you affirm that you have read, understood and accepted this agreement and the permanent removal of WordPress administrative access for this deployment.
TERMS;
    }
    public static function hash(): string { return hash('sha256', self::text()); }
}

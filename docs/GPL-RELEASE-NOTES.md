# GPL release notes

Release scope: public source release under GPL-2.0-or-later. This release does not include live hosting deployment or WordPress.org directory submission.

## Current declarations

The plugin header and standalone runtime declare **GPL-2.0-or-later**. `readme.txt` uses the equivalent “GPLv2 or later” wording. `LICENSE` contains the GNU GPL version 2 text. These declarations consistently identify the software license for this source release.

WordPress itself uses GPLv2 or later. Its official licensing page describes the platform's licensing position. [WordPress license](https://wordpress.org/about/license/)

## Source rights and deployment policy

Recipients' GPL rights to use, study, modify and redistribute covered code remain separate from access to a particular hosted deployment. The deployment policy removes WordPress administration from the restored instance; it is not a claim that the PHP source cannot be inspected, modified or run elsewhere by an authorized recipient.

The versioned deployment agreement in `includes/class-deployment-terms.php` explicitly preserves WordPress/GPL and other applicable open-source rights. Its restrictions on proprietary components and unauthorized access must not be presented as restrictions on exercising GPL rights in this project. Do not market the GPL code as proprietary, unmodifiable or confidential from its recipients. GPLv2 section 6 prohibits adding restrictions to its granted rights. [GNU GPL version 2, section 6](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html#section6)

The agreement is draft service/deployment text, with exceptions for applicable licenses and rights that cannot lawfully be waived. Its acceptance records bind a particular image and terms digest; they are not a legal enforceability certification. The hosting provider and server owner retain their independent infrastructure authority.

## Public source distribution

The publisher has authorized public source distribution. Distribution to recipients carries the applicable license obligations. The GPL does not itself require private internal modifications to be published publicly. [GNU GPL FAQ: private modifications](https://www.gnu.org/licenses/gpl-faq.en.html#GPLRequireSourcePostedPublic)

For this release and subsequent distributions:

1. Retain the license, notices and matching readable source, including the scripts needed to build or install the delivered software. Provide required corresponding source to recipients through an appropriate channel.
2. Review the permissions and notices for every included code or visual asset. Brand names, customer content, private site databases and other separately licensed material are not automatically relicensed by the project's GPL declaration.
3. Keep server keys, credentials, customer images, runtime databases and operator backups out of the published source. Encryption does not replace distribution obligations for software contained in an image.
4. Distinguish a GitHub source release from WordPress.org directory submission. Directory inclusion has separate code, asset, behavior and documentation rules; acceptance is not established by choosing a GPL-compatible license. [WordPress plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)

These notes describe the current project packaging and license boundaries. They do not provide a jurisdiction-specific legal opinion or certify a hosted deployment.

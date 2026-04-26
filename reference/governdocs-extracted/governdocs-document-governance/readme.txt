=== GovernDocs - Document Management & Annual Reports ===

Tags: document management, legal, policy, policies, annual reports
Contributors: quicksnail
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.1
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish and manage policies, meetings agendas, meeting minutes & annual reports in WordPress. Full document management with easy shortcode output.

== Description ==

GovernDocs is a document management & annual reports plugin for WordPress.

It is designed for organisations that need a clearer way to manage and publish important records such as policies, meetings documents and reports without relying on regular blog posts or pages.

The plugin adds dedicated content types so these documents can be stored in a structured way, with fields for files, dates, versions, status information, and other governance-related details. You can then display them on your website using shortcodes.

GovernDocs is suitable for councils, associations, clubs, schools, law firms, committees, non-profits and other organisations that need a simple way to manage official documents in WordPress.

Upgrade to the PRO version to get a full audit log for Policies & Reports as well as the ability to add supporting docs.

##GovernDocs Document Management Features

* Dedicated content types for **Policies**, **Meetings** and **Reports**
* Structured fields for governance-related content
* Attach files to records
* Publish documents as web content, file downloads or both
* Display items using shortcodes
* Show document metadata such as type, extension, size, dates, version and status
* Store previous versions for policies
* Clean admin editing experience for document-based content

GovernDocs helps separate governance content from normal website content so your official records are easier to manage and easier for visitors to find.

##Policy Features

Use Policies for official policy documents that are displayed on the front end of your website, or you can keep them as internal policies only. 

Each Policy has fields to include:

* Version
* Policy ID
* Status
* Policy Owner
* Responsible Role
* Approving Authority
* Downloadable file
* Effective Date
* Approval Date
* Next Review Date
* Last Review Date
* Department

Plus other fields relating to governance, compliance and records.

##Meeting Features

Use Meetings for meeting-related entries such as agenda and minutes. These can be published as web content, downloadable files or both.

Each Policy has fields to include:

* Meeting Date
* Meeting ID
* Status
* Chair
* Minute Taker
* Location
* Description
* Agenda File
* Agenda Publish Date
* Agenda Notes
* Minutes File
* Minutes Publish Date
* Minutes Notes
* Department

##Report Features

Use Reports for reports, strategic reports, operational reports and other formal publications.

Each Report has fields to include:

* Report Type (General, Annual Report, Financial Report etc)
* Report ID
* Status
* Author
* Published Date
* Report Date
* Description
* Department

##Shortcode support

GovernDocs includes shortcode output so you can place document records into pages, posts, templates or any area that accepts shortcodes.

Examples of the shortcodes:

`[governdocs type="policy" id="123"]`
`[governdocs type="meeting" id="123"]`
`[governdocs type="report" id="123"]`

`[governdocs
type="policy"
id="123"
show_icon="1"
button="1"
display="card"
desc_location="below_title"
class="custom-policy-card"
fields="ext,size,type,status,version,effective_date,approval_date,review_date,last_reviewed_date,owner,approving_authority,policy_id"
order="ext,size,type,status,version,effective_date,approval_date,review_date,last_reviewed_date,owner,approving_authority,policy_id"
]`


= Who needs document management =

GovernDocs is built for websites that need more structure than a normal blog or page setup, especially where documents need to be maintained over time.

Examples include:

* Local government and council websites
* Associations and member organisations
* Sporting clubs and community groups
* Schools and education providers
* Compliance-focused business websites
* Boards and committees

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/governdocs/` directory, or install the plugin through the WordPress plugins screen by searching for GovernDocs.
2. Activate the plugin through the WordPress plugins screen.
3. After activation, you will see new admin menu items for governance content.
4. Add your Policies, Meetings, and Reports.
5. Insert the provided shortcodes into pages or posts where you want the content displayed.
6. You now have a full document management system for WordPress.

== Frequently Asked Questions ==

= What does GovernDocs add to WordPress? =

GovernDocs adds custom content types for governance-related documents, along with structured fields and shortcode output. It is a full document management system.

= Can I upload PDF files? =

Yes. You can attach document files to supported content types.

= Can I show a document as a web page instead of only a file download? =

Yes. Documents can be published as web content, file-based content or both.

= Can I display documents on a normal page? =

Yes. Use the included shortcodes to output individual items or document lists inside regular WordPress content.

= Is this plugin only for councils? =

No. It is suitable for any organisation that publishes formal documents and wants a more structured way to manage them.

== Screenshots ==

1. Meetings list screen - sortable and searchable
2. Meetings edit screen for agenda and minutes content
3. Reports edit screen with file and metadata fields
4. Policies list screen - sortable and searchable
5. Reports list screen - sortable and searchable
6. Front-end shortcode output showing all document types

== Changelog ==

= 1.0.2 - 1 Apr 2026 =
* Fix issue CMB2 default $editor_id not being set

= 1.0.1 - 30 Mar 2026 =
* Fix issue with Department labels being called Category
* Minor styling updates

= 1.0.0 =
* Initial release

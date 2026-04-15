<?php
/**
 * Default FAQ catalog.
 *
 * Generic, content-agnostic glossary entries seeded into the ot_faq CPT on
 * first plugin activation. These explain universal trust-center concepts and
 * make no claims about the specific company running the plugin.
 *
 * Entry schema:
 *   'slug' => [
 *       'question' => 'Human-readable question (becomes post_title).',
 *       'answer'   => 'Plain prose answer (wrapped in a Gutenberg paragraph
 *                      block at seed time and stored as post_content).',
 *   ]
 *
 * Filterable via the `opentrust_faq_catalog` filter. Because seeding is gated
 * by the `opentrust_faqs_seeded` option, editing this file after first
 * activation has no effect on existing installs.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'what-is-a-trust-center' => [
		'question' => __( 'What is a trust center?', 'opentrust' ),
		'answer'   => __( 'A trust center is a public page where a company shares information about how it handles security, privacy, and compliance. It usually includes security policies, a list of subprocessors, compliance certifications, and details about how customer data is handled. The goal is to give customers, partners, and prospects one place to answer due diligence questions without having to email anyone.', 'opentrust' ),
	],

	'what-is-a-dpa' => [
		'question' => __( 'What is a Data Processing Agreement (DPA)?', 'opentrust' ),
		'answer'   => __( 'A Data Processing Agreement, or DPA, is a contract between a company that collects personal data and a company that processes that data on its behalf. It defines what data can be processed, for what purpose, how long it can be kept, and what security measures must be in place. Under privacy laws like the GDPR, a DPA is required whenever one company processes personal data for another.', 'opentrust' ),
	],

	'what-is-a-subprocessor' => [
		'question' => __( 'What is a subprocessor?', 'opentrust' ),
		'answer'   => __( 'A subprocessor is a third-party service that a company uses to help deliver its product, and that may come into contact with customer data along the way. Common examples include cloud hosting providers, email delivery services, analytics platforms, and customer support tools. Companies publish subprocessor lists so customers can see exactly which vendors may handle their data.', 'opentrust' ),
	],

	'controller-vs-processor' => [
		'question' => __( 'What is the difference between a data controller and a data processor?', 'opentrust' ),
		'answer'   => __( "The data controller is the party that decides why and how personal data is collected and used. The data processor is the party that handles that data on the controller's behalf, following the controller's instructions. A SaaS customer is usually the controller of their end-user data, while the SaaS vendor acts as the processor. Each role carries different legal responsibilities under privacy laws like the GDPR.", 'opentrust' ),
	],

	'what-is-personal-data' => [
		'question' => __( 'What is personal data?', 'opentrust' ),
		'answer'   => __( 'Personal data is any information that can be used to identify a living person, either on its own or when combined with other information. Obvious examples include names, email addresses, phone numbers, and home addresses. Less obvious examples include IP addresses, device identifiers, cookies, and location data. Privacy laws such as the GDPR and CCPA treat personal data as something that must be collected, stored, and shared with care.', 'opentrust' ),
	],

	'what-is-responsible-disclosure' => [
		'question' => __( 'What is responsible disclosure?', 'opentrust' ),
		'answer'   => __( 'Responsible disclosure is the practice of reporting a security vulnerability privately to the company that owns the affected system, giving them a reasonable amount of time to fix it before any details are shared publicly. It protects users from being exposed to a known issue before a patch is available. Most trust centers include a contact address or form for reporting vulnerabilities this way.', 'opentrust' ),
	],

	'what-is-a-compliance-certification' => [
		'question' => __( 'What is a compliance certification?', 'opentrust' ),
		'answer'   => __( "A compliance certification is a formal statement, usually issued by an independent auditor, confirming that a company meets the requirements of a specific security or privacy standard. Certifications give customers a way to trust a company's practices without having to inspect them directly. The scope, issuing body, and validity period are typically listed alongside each certification in a trust center.", 'opentrust' ),
	],

	'what-is-a-security-policy' => [
		'question' => __( 'What is a security policy?', 'opentrust' ),
		'answer'   => __( 'A security policy is a written document that describes how a company protects its systems, data, and people. Policies commonly cover topics like access control, incident response, acceptable use, vendor management, and business continuity. Publishing policies in a trust center lets customers see how security is handled without needing to sign an NDA first.', 'opentrust' ),
	],

	'why-publish-subprocessors' => [
		'question' => __( 'Why do companies publish a list of subprocessors?', 'opentrust' ),
		'answer'   => __( 'Publishing a subprocessor list is a transparency practice, and in many cases a legal requirement, that lets customers see every third party that may handle their data. It gives customers the chance to review new vendors before they start processing data, and it makes it easier to meet their own compliance obligations. Most trust centers also offer a way to be notified when the list changes.', 'opentrust' ),
	],

	'what-is-a-data-practice' => [
		'question' => __( 'What is a data practice?', 'opentrust' ),
		'answer'   => __( 'A data practice describes a specific way a company collects, uses, stores, or shares information. Each practice usually spells out what data is involved, why it is collected, how long it is kept, and who it is shared with. Grouping data practices by category, such as account data, usage data, or support data, helps customers understand exactly what happens to their information.', 'opentrust' ),
	],
];

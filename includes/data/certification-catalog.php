<?php
/**
 * Certification catalog.
 *
 * Curated list of common compliance frameworks, regulations, and
 * certifications shown in the admin typeahead on the ot_certification
 * create screen. Each entry sets `_ot_cert_type` to either `certified`
 * (third-party audited, has a certificate with dates and an issuing body)
 * or `compliant` (self-attested adherence to a standard or regulation).
 *
 * Certified entries include `_ot_cert_issuing_body`. Compliant entries
 * deliberately omit it because there is no auditor.
 *
 * Never auto-filled: issue date, expiry date, status, badge. Those are
 * user specific.
 *
 * Extend without forking via the `opentrust_certification_catalog` filter.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [

	'soc-2-type-i' => [
		'name'    => 'SOC 2 Type I',
		'aliases' => [ 'soc2 type 1', 'soc 2 type 1', 'soc2 type i' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'Point in time audit of the design of controls against the AICPA Trust Services Criteria (Security, Availability, Processing Integrity, Confidentiality, Privacy).',
			'_ot_cert_issuing_body' => 'AICPA',
		],
		'fields_review' => [],
	],

	'soc-2-type-ii' => [
		'name'    => 'SOC 2 Type II',
		'aliases' => [ 'soc2 type 2', 'soc 2 type 2', 'soc2', 'soc 2' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'Audit of the design and operating effectiveness of controls over a period (typically 6 to 12 months) against the AICPA Trust Services Criteria.',
			'_ot_cert_issuing_body' => 'AICPA',
		],
		'fields_review' => [],
	],

	'soc-1-type-ii' => [
		'name'    => 'SOC 1 Type II',
		'aliases' => [ 'soc1 type 2', 'soc 1', 'ssae 18' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'Audit of controls relevant to a service organization\'s impact on user entities\' internal control over financial reporting, conducted under SSAE 18.',
			'_ot_cert_issuing_body' => 'AICPA',
		],
		'fields_review' => [],
	],

	'iso-27001' => [
		'name'    => 'ISO/IEC 27001',
		'aliases' => [ 'iso 27001', 'iso27001', 'isms' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'International standard for establishing, implementing, and maintaining an Information Security Management System (ISMS).',
			'_ot_cert_issuing_body' => 'ISO/IEC accredited certification body',
		],
		'fields_review' => [],
	],

	'iso-27017' => [
		'name'    => 'ISO/IEC 27017',
		'aliases' => [ 'iso 27017', 'iso27017' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'Code of practice providing additional information security controls for cloud service providers and cloud customers, extending ISO/IEC 27002.',
			'_ot_cert_issuing_body' => 'ISO/IEC accredited certification body',
		],
		'fields_review' => [],
	],

	'iso-27018' => [
		'name'    => 'ISO/IEC 27018',
		'aliases' => [ 'iso 27018', 'iso27018' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'Code of practice for the protection of personally identifiable information (PII) in public clouds acting as PII processors.',
			'_ot_cert_issuing_body' => 'ISO/IEC accredited certification body',
		],
		'fields_review' => [],
	],

	'iso-27701' => [
		'name'    => 'ISO/IEC 27701',
		'aliases' => [ 'iso 27701', 'iso27701', 'pims' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'Extension to ISO/IEC 27001 specifying requirements for a Privacy Information Management System (PIMS) covering PII controllers and processors.',
			'_ot_cert_issuing_body' => 'ISO/IEC accredited certification body',
		],
		'fields_review' => [],
	],

	'iso-9001' => [
		'name'    => 'ISO 9001',
		'aliases' => [ 'iso9001', 'quality management' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'International standard for Quality Management Systems (QMS), covering processes for consistent product and service delivery and continual improvement.',
			'_ot_cert_issuing_body' => 'ISO accredited certification body',
		],
		'fields_review' => [],
	],

	'hipaa' => [
		'name'    => 'HIPAA',
		'aliases' => [ 'hipaa', 'health insurance portability', 'phi' ],
		'fields'  => [
			'_ot_cert_type'        => 'compliant',
			'_ot_cert_description' => 'US federal law governing the protection and confidential handling of individually identifiable health information (PHI) by covered entities and business associates.',
		],
		'fields_review' => [],
	],

	'pci-dss' => [
		'name'    => 'PCI DSS',
		'aliases' => [ 'pci', 'pci-dss', 'payment card industry' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'Payment Card Industry Data Security Standard defining technical and operational requirements for organizations that store, process, or transmit cardholder data.',
			'_ot_cert_issuing_body' => 'PCI Security Standards Council',
		],
		'fields_review' => [],
	],

	'gdpr' => [
		'name'    => 'GDPR',
		'aliases' => [ 'gdpr', 'general data protection regulation', 'eu gdpr' ],
		'fields'  => [
			'_ot_cert_type'        => 'compliant',
			'_ot_cert_description' => 'European Union regulation governing the processing of personal data of individuals in the EU and EEA, including data subject rights and cross-border transfers.',
		],
		'fields_review' => [],
	],

	'ccpa' => [
		'name'    => 'CCPA',
		'aliases' => [ 'ccpa', 'cpra', 'california consumer privacy act' ],
		'fields'  => [
			'_ot_cert_type'        => 'compliant',
			'_ot_cert_description' => 'California state law granting consumers rights over personal information collected by businesses, as amended by the California Privacy Rights Act (CPRA).',
		],
		'fields_review' => [],
	],

	'hitrust-csf' => [
		'name'    => 'HITRUST CSF',
		'aliases' => [ 'hitrust', 'hitrust csf', 'csf' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'Certifiable framework that harmonizes requirements from HIPAA, ISO, NIST, PCI, and other standards into a single set of controls for managing information risk.',
			'_ot_cert_issuing_body' => 'HITRUST Alliance',
		],
		'fields_review' => [],
	],

	'cyber-essentials' => [
		'name'    => 'Cyber Essentials',
		'aliases' => [ 'ce', 'uk cyber essentials' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'UK government-backed scheme covering five technical controls (firewalls, secure configuration, access control, malware protection, patch management), verified by self-assessment.',
			'_ot_cert_issuing_body' => 'IASME (on behalf of UK NCSC)',
		],
		'fields_review' => [],
	],

	'cyber-essentials-plus' => [
		'name'    => 'Cyber Essentials Plus',
		'aliases' => [ 'ce+', 'ce plus', 'cyber essentials +' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'Higher assurance tier of Cyber Essentials, adding hands-on technical verification and vulnerability testing of the same five control areas by an accredited assessor.',
			'_ot_cert_issuing_body' => 'IASME (on behalf of UK NCSC)',
		],
		'fields_review' => [],
	],

	'fedramp' => [
		'name'    => 'FedRAMP',
		'aliases' => [ 'fedramp', 'federal risk and authorization management program' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'US government program that standardizes security assessment, authorization, and continuous monitoring for cloud products and services used by federal agencies.',
			'_ot_cert_issuing_body' => 'US General Services Administration (GSA)',
		],
		'fields_review' => [],
	],

	'cmmc' => [
		'name'    => 'CMMC',
		'aliases' => [ 'cmmc', 'cybersecurity maturity model certification' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'US Department of Defense framework certifying contractor cybersecurity practices for the protection of Federal Contract Information (FCI) and Controlled Unclassified Information (CUI).',
			'_ot_cert_issuing_body' => 'Cyber AB (on behalf of US DoD)',
		],
		'fields_review' => [],
	],

	'tisax' => [
		'name'    => 'TISAX',
		'aliases' => [ 'tisax', 'trusted information security assessment exchange' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'Information security assessment and exchange mechanism developed for the automotive industry, based on the VDA ISA catalog.',
			'_ot_cert_issuing_body' => 'ENX Association',
		],
		'fields_review' => [],
	],

	'c5' => [
		'name'    => 'BSI C5',
		'aliases' => [ 'c5', 'cloud computing compliance criteria catalogue' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'German government catalog of minimum baseline security requirements for cloud service providers, assessed through an independent audit report.',
			'_ot_cert_issuing_body' => 'Germany Federal Office for Information Security (BSI)',
		],
		'fields_review' => [],
	],

	'irap' => [
		'name'    => 'IRAP',
		'aliases' => [ 'irap', 'information security registered assessors program' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'Australian government assessment of an ICT system against the Information Security Manual (ISM), performed by endorsed assessors for use by Australian government entities.',
			'_ot_cert_issuing_body' => 'Australian Signals Directorate (ASD)',
		],
		'fields_review' => [],
	],

	'hds' => [
		'name'    => 'HDS',
		'aliases' => [ 'hds', 'hebergeur de donnees de sante', 'health data hosting' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'French certification required for hosting personal health data, covering infrastructure and application hosting activities under the French Public Health Code.',
			'_ot_cert_issuing_body' => 'French Agence du Numerique en Sante (ANS)',
		],
		'fields_review' => [],
	],

	'csa-star-level-1' => [
		'name'    => 'CSA STAR Level 1',
		'aliases' => [ 'star level 1', 'csa star 1', 'caiq' ],
		'fields'  => [
			'_ot_cert_type'        => 'compliant',
			'_ot_cert_description' => 'Self-assessment published to the Cloud Security Alliance STAR registry, documenting cloud security controls against the Cloud Controls Matrix (CCM) and CAIQ.',
		],
		'fields_review' => [],
	],

	'csa-star-level-2' => [
		'name'    => 'CSA STAR Level 2',
		'aliases' => [ 'star level 2', 'csa star 2', 'star certification' ],
		'fields'  => [
			'_ot_cert_type'         => 'certified',
			'_ot_cert_description'  => 'Third-party audit of cloud security controls against the Cloud Controls Matrix, performed alongside an ISO/IEC 27001 certification or a SOC 2 attestation.',
			'_ot_cert_issuing_body' => 'Cloud Security Alliance (via accredited auditor)',
		],
		'fields_review' => [],
	],

];

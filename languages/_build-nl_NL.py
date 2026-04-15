#!/usr/bin/env python3
"""
Build opentrust-nl_NL.po from opentrust.pot using a curated Dutch dictionary.

Strings not in the dictionary are left with empty msgstr (fallback to English).
Translations are flagged `fuzzy` when not a verbatim literal — so a human
translator can review them without trust.

This script is a build-time helper; the generated .po/.mo are committed, not
the script runtime. Safe to delete after the .po is reviewed.
"""
from __future__ import annotations
import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).parent
POT = ROOT / "opentrust.pot"
PO  = ROOT / "opentrust-nl_NL.po"

# ── Dutch translations ────────────────────────────────
# Keep conservative and literal. Proper nouns (OpenTrust, SOC 2, ISO 27001,
# HIPAA, DPA, Anthropic, OpenAI) stay English. UI jargon prefers the common
# Dutch WordPress style ("Bewaren", "Opslaan", "Bekijken", "Toevoegen").

NL: dict[str, str] = {
    # ── Plugin metadata ─────────────────────────────
    "OpenTrust": "OpenTrust",
    "A self-hosted, open-source trust center for publishing security policies, subprocessors, certifications, and data practices.":
        "Een zelf-gehost, open-source vertrouwenscentrum voor het publiceren van beveiligingsbeleid, subverwerkers, certificeringen en gegevenspraktijken.",

    # ── Trust center public page — section headers ──
    # "Trust Center" stays untranslated: in Dutch B2B SaaS it is an established
    # loan-term and "Vertrouwenscentrum" reads as clinical / stiff.
    "Trust Center": "Trust Center",
    "Transparency and security you can trust.": "Transparante beveiliging om op te vertrouwen.",
    "Certifications": "Certificeringen",
    "Certifications & Compliance": "Certificeringen & compliance",
    "Our active certifications and compliance frameworks demonstrate our commitment to protecting your data.":
        "Onze actieve certificeringen en compliance-frameworks laten zien hoe serieus we jouw gegevens beschermen.",
    "Compliant": "Compliant",
    "Security Policies": "Beveiligingsbeleid",
    "Our published security and compliance policies are regularly reviewed and updated.":
        "Ons gepubliceerde beveiligings- en compliancebeleid wordt regelmatig herzien en bijgewerkt.",
    "Policies": "Beleid",
    "Policy": "Beleid",
    "Version": "Versie",
    "Last Updated": "Laatst bijgewerkt",
    "Actions": "Acties",
    "Subprocessors": "Subverwerkers",
    "Third-party services that process data on our behalf, along with their purposes and data handling agreements.":
        "Externe diensten die namens ons gegevens verwerken, met hun doel en verwerkersovereenkomsten.",
    "Location": "Locatie",
    "DPA": "DPA",
    "Signed": "Ondertekend",
    "more": "meer",
    "Data Practices": "Gegevensverwerking",
    "What we collect and how we handle your data.": "Welke gegevens we verzamelen en hoe we ze gebruiken.",
    "Overview": "Overzicht",
    "Search": "Zoeken",
    "Search policies, subprocessors, certifications…": "Zoek in beleid, subverwerkers, certificeringen…",

    # ── Hero ────────────────────────────────────────
    "%d Active Certification": "%d Actieve certificering",
    "%d Active Certifications": "%d Actieve certificeringen",
    "Last updated": "Laatst bijgewerkt",
    "Updated %s": "Bijgewerkt op %s",
    "Updated %d minute ago": "%d minuut geleden bijgewerkt",
    "Updated %d minutes ago": "%d minuten geleden bijgewerkt",
    "Updated %d hour ago": "%d uur geleden bijgewerkt",
    "Updated %d hours ago": "%d uur geleden bijgewerkt",
    "Updated %d day ago": "%d dag geleden bijgewerkt",
    "Updated %d days ago": "%d dagen geleden bijgewerkt",

    # ── Certifications ──────────────────────────────
    "Active": "Actief",
    "In Progress": "In aanvraag",
    "Expired": "Verlopen",
    "Not Active": "Niet actief",
    "Issued %s": "Uitgegeven op %s",
    "Expires %s": "Verloopt op %s",
    "Issuing Body": "Uitgegeven door",
    "Status": "Status",
    "Valid From": "Geldig vanaf",
    "Expiry Date": "Vervaldatum",
    "View Certificate": "Certificaat bekijken",
    "Download": "Downloaden",

    # ── Policies ────────────────────────────────────
    "View %s": "%s bekijken",
    "View Policy": "Beleid bekijken",
    "Version %s": "Versie %s",
    "v%s": "v%s",
    "v%d": "v%d",
    "Version history (%d)": "Versiegeschiedenis (%d)",
    "Effective: %s": "Ingangsdatum: %s",
    "Updated: %s": "Bijgewerkt: %s",
    "Category": "Categorie",
    "Back to Trust Center": "Terug naar Trust Center",
    "Back": "Terug",
    "Print": "Afdrukken",
    "Download PDF": "Download PDF",

    # ── Subprocessors ───────────────────────────────
    "Name": "Naam",
    "Purpose": "Doel",
    "Data Processed": "Verwerkte gegevens",
    "Country": "Land",
    "Website": "Website",
    "DPA Signed": "DPA ondertekend",
    "Yes": "Ja",
    "No": "Nee",

    # ── Data practices ──────────────────────────────
    "Collected": "Verzameld",
    "Stored": "Opgeslagen",
    "Shared": "Gedeeld",
    "Sold": "Verkocht",
    "Encrypted": "Versleuteld",
    "Legal Basis": "Wettelijke grondslag",
    "Retention": "Bewaartermijn",
    "Shared With": "Gedeeld met",
    "Data Items": "Gegevensitems",
    "View %1$d more %2$s items": "Bekijk nog %1$d %2$s-items",

    # ── Chat UI ─────────────────────────────────────
    "Ask anything about our security and compliance…": "Stel een vraag over onze beveiliging en compliance…",
    "Ask anything about our security…": "Stel een vraag over onze beveiliging…",
    "Ask AI": "Vraag de AI",
    "Ask %s — Trust Center": "Vraag het aan %s — Trust Center",
    "Send": "Versturen",
    "Stop": "Stoppen",
    "Thinking…": "Denkt na…",
    "Connection lost. Retry?": "Verbinding verbroken. Opnieuw proberen?",
    "Retry": "Opnieuw proberen",
    "Contact security team →": "Neem contact op met het beveiligingsteam →",
    "Copy": "Kopiëren",
    "Copied": "Gekopieerd",
    "Share": "Delen",
    "Link copied": "Link gekopieerd",
    "Start a new conversation": "Start een nieuw gesprek",
    "This conversation is getting long. Start fresh for better answers.":
        "Dit gesprek wordt lang — begin opnieuw voor de beste antwoorden.",
    "Sources": "Bronnen",
    "I don't see enough information in our trust center to answer that confidently.":
        "In ons Trust Center staat niet genoeg informatie om die vraag met zekerheid te beantwoorden.",
    "The AI provider returned an error. Please try again.":
        "De AI-provider gaf een fout. Probeer het opnieuw.",
    "AI is temporarily unavailable. Please try again in a few minutes or browse our published content.":
        "De AI is tijdelijk niet beschikbaar. Probeer het over enkele minuten opnieuw of bekijk onze gepubliceerde content.",
    "Message is too long.": "Het bericht is te lang.",
    "Please wait a moment before asking again.": "Wacht even voordat je opnieuw een vraag stelt.",
    "Cite source": "Bron citeren",
    "You": "Jij",
    "just now": "zojuist",
    "No content returned by the model.": "Het model gaf geen antwoord terug.",
    "AI-generated answer. Not legal, security, or compliance advice. Verify against the sources above.":
        "Dit antwoord is door AI gegenereerd. Geen juridisch, beveiligings- of compliance-advies. Controleer het altijd aan de hand van de bronnen hierboven.",
    "Are you SOC 2 compliant?": "Zijn jullie SOC 2-compliant?",
    "Where is customer data stored?": "Waar worden klantgegevens opgeslagen?",
    "Which subprocessors do you use?": "Welke subverwerkers gebruiken jullie?",
    "What is your incident response process?": "Wat is jullie incident-responseproces?",

    # ── Subscribe / notifications ───────────────────
    "Subscribe to updates": "Abonneer op updates",
    "Get email notifications when %s updates their trust center.":
        "Ontvang e-mailmeldingen zodra %s hun Trust Center bijwerkt.",
    "Manage notifications for %s.": "Beheer meldingen voor %s.",
    "Email": "E-mail",
    "Email address": "E-mailadres",
    "Your name": "Je naam",
    "Name (optional)": "Naam (optioneel)",
    "Company": "Bedrijf",
    "Company (optional)": "Bedrijf (optioneel)",
    "Subscribe": "Abonneren",
    "Unsubscribe": "Uitschrijven",
    "Manage subscription": "Abonnement beheren",
    "Please enter a valid email address.": "Voer een geldig e-mailadres in.",
    "Please check your inbox and click the confirmation link.":
        "Controleer je inbox en klik op de bevestigingslink.",
    "Invalid request.": "Ongeldig verzoek.",
    "Please complete the security check.": "Voltooi de beveiligingscontrole.",
    "Please select at least one category.": "Selecteer ten minste één categorie.",
    "Thank you for subscribing to trust center updates from %s. Please confirm your subscription by clicking the button below.":
        "Bedankt dat je je hebt aangemeld voor Trust Center-updates van %s. Bevestig je aanmelding door op de knop hieronder te klikken.",
    "Confirm your subscription to %s Trust Center updates":
        "Bevestig je aanmelding voor Trust Center-updates van %s",
    "Hi %s,": "Hoi %s,",
    "[%1$s] Policy updated: %2$s": "[%1$s] Beleid bijgewerkt: %2$s",
    "%1$s has updated the following policy on our trust center:":
        "%1$s heeft het volgende beleid bijgewerkt op het Trust Center:",

    # ── Footer ──────────────────────────────────────
    "© %1$s %2$s. All rights reserved.": "© %1$s %2$s. Alle rechten voorbehouden.",
    "Powered by %1$s. Grounded in %2$s.": "Mogelijk gemaakt door %1$s. Gebaseerd op %2$s.",
    "Grounded in %s.": "Gebaseerd op %s.",
    "Ask about %s's security and compliance": "Vraag ons over de beveiliging en compliance van %s",

    # ── Policy single version banners ───────────────
    "You are viewing version %1$s. %2$s":
        "Je bekijkt versie %1$s. %2$s",
    "This version takes effect on %1$s. %2$s":
        "Deze versie gaat in op %1$s. %2$s",
    "View current version": "Huidige versie bekijken",
    "View previous version": "Vorige versie bekijken",

    # ── Empty states ────────────────────────────────
    "No policies published yet.": "Nog geen beleid gepubliceerd.",
    "No certifications published yet.": "Nog geen certificeringen gepubliceerd.",
    "No subprocessors published yet.": "Nog geen subverwerkers gepubliceerd.",
    "No data practices published yet.": "Nog geen gegevenspraktijken gepubliceerd.",

    # ── Please enter a question ─────────────────────
    "Please enter a question.": "Voer een vraag in.",
    "Invalid nonce — refresh the page and try again.":
        "Ongeldige nonce — ververs de pagina en probeer opnieuw.",
    "Please complete the anti-abuse challenge and try again.":
        "Voltooi de anti-misbruikcontrole en probeer opnieuw.",
}

# ── .po parser ─────────────────────────────────────────
def parse_pot(text: str) -> tuple[str, list[dict]]:
    """Parse a .pot into (header_block, [entries])."""
    # The header is the first msgid/msgstr pair with empty msgid.
    # Each subsequent entry is a block separated by blank lines.
    blocks = re.split(r"\n\n+", text.strip())
    if not blocks:
        return "", []

    header = blocks[0]
    entries = []
    for block in blocks[1:]:
        entry = {"comments": [], "refs": [], "flags": [], "msgid": "", "msgid_plural": "", "msgstr": [""], "raw": block}

        # Collect comments and extract msgid/msgstr
        current = None
        buf = []
        lines = block.split("\n")
        i = 0
        while i < len(lines):
            line = lines[i]
            if line.startswith("#."):
                entry["comments"].append(line)
            elif line.startswith("#:"):
                entry["refs"].append(line)
            elif line.startswith("#,"):
                entry["flags"].append(line)
            elif line.startswith("#"):
                entry["comments"].append(line)
            elif line.startswith("msgid_plural "):
                if current == "msgid":
                    entry["msgid"] = "".join(buf)
                current = "msgid_plural"
                buf = [unquote(line[len("msgid_plural "):])]
            elif line.startswith("msgid "):
                current = "msgid"
                buf = [unquote(line[len("msgid "):])]
            elif line.startswith("msgstr[0] "):
                if current == "msgid_plural":
                    entry["msgid_plural"] = "".join(buf)
                current = "msgstr0"
                buf = [unquote(line[len("msgstr[0] "):])]
                entry["msgstr"] = ["", ""]
            elif line.startswith("msgstr[1] "):
                if current == "msgstr0":
                    entry["msgstr"][0] = "".join(buf)
                current = "msgstr1"
                buf = [unquote(line[len("msgstr[1] "):])]
            elif line.startswith("msgstr "):
                if current == "msgid":
                    entry["msgid"] = "".join(buf)
                current = "msgstr"
                buf = [unquote(line[len("msgstr "):])]
                entry["msgstr"] = [""]
            elif line.startswith('"'):
                buf.append(unquote(line))
            i += 1

        # Flush last buffer
        if current == "msgid":
            entry["msgid"] = "".join(buf)
        elif current == "msgid_plural":
            entry["msgid_plural"] = "".join(buf)
        elif current == "msgstr":
            entry["msgstr"] = ["".join(buf)]
        elif current == "msgstr0":
            entry["msgstr"][0] = "".join(buf)
        elif current == "msgstr1":
            entry["msgstr"][1] = "".join(buf)

        entries.append(entry)

    return header, entries

def unquote(s: str) -> str:
    """Unquote a .po string literal."""
    s = s.strip()
    if s.startswith('"') and s.endswith('"'):
        s = s[1:-1]
    # Unescape basic sequences
    return s.replace('\\"', '"').replace('\\n', '\n').replace('\\t', '\t').replace('\\\\', '\\')

def quote(s: str) -> str:
    """Quote a Python string as a .po literal (no multiline splitting)."""
    s = s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')
    return f'"{s}"'

def render_entry(entry: dict, translation: str | None = None, plural: str | None = None) -> str:
    out = []
    out.extend(entry["comments"])
    out.extend(entry["refs"])
    if translation:
        if "#, fuzzy" not in entry["flags"]:
            out.append("#, fuzzy")
    out.extend(entry["flags"])

    out.append(f"msgid {quote(entry['msgid'])}")
    if entry["msgid_plural"]:
        out.append(f"msgid_plural {quote(entry['msgid_plural'])}")
        out.append(f"msgstr[0] {quote(translation or '')}")
        out.append(f"msgstr[1] {quote(plural or translation or '')}")
    else:
        out.append(f"msgstr {quote(translation or '')}")

    return "\n".join(out)

# ── Build ──────────────────────────────────────────────
def build():
    if not POT.exists():
        sys.exit(f"No .pot at {POT}")

    header, entries = parse_pot(POT.read_text(encoding="utf-8"))

    # Rewrite header for nl_NL
    header = header.replace(
        '"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"',
        '"PO-Revision-Date: 2026-04-14 21:55+0000\\n"'
    ).replace(
        '"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"',
        '"Last-Translator: OpenTrust bundled starter <noreply@opentrust.dev>\\n"'
    ).replace(
        '"Language-Team: LANGUAGE <LL@li.org>\\n"',
        '"Language-Team: Dutch (Netherlands)\\n"'
    )
    # Inject Language + Plural-Forms before X-Generator
    if '"Language:' not in header:
        header = header.replace(
            '"X-Generator:',
            '"Language: nl_NL\\n"\n"Plural-Forms: nplurals=2; plural=(n != 1);\\n"\n"X-Generator:'
        )

    parts = [header]
    translated = 0
    for e in entries:
        msgid = e["msgid"]
        nl = NL.get(msgid)
        # Plural forms: use the same translation for both if dict has it
        plural_nl = None
        if e["msgid_plural"]:
            plural_nl = NL.get(e["msgid_plural"], nl)
        if nl or plural_nl:
            translated += 1
        parts.append(render_entry(e, nl, plural_nl))

    PO.write_text("\n\n".join(parts) + "\n", encoding="utf-8")
    print(f"Wrote {PO.name} — {translated}/{len(entries)} strings translated")

if __name__ == "__main__":
    build()

<!--suppress HtmlDeprecatedAttribute -->
<h1 align="center">
    <img src="./assets/images/logo.svg" width="350" alt="ESN Membership Manager Logo"/><br>
    ESN Membership Manager
</h1>

<img src="./assets/images/esnAward.svg" align="right" height="150" alt="Winner of the IT Award at the ESN Awards 2026"/>

ESN Membership Manager is a robust Drupal module designed to streamline the management of memberships within the Erasmus
Student Network (ESN). As part of the **Omnia** umbrella package, it provides a comprehensive framework for handling
applications for ESNcards, Free Passes, and Guest Passes, integrating seamlessly with your existing Drupal
infrastructure.

## Features

- **🗂️ Membership Dashboard:** A centralized interface to filter, sort, and manage all membership applications.
- **✅ Interactive Review:** Streamlined workflow to approve or reject applications, including email notifications.
- **🛡️ ID & Status Verification:** Built-in concepts to verify applicant eligibility (with manual fallback) to ensure
  only eligible members receive passes.
- **📧 Mandatory Email Verification:** Ensures secure and verified communication with all applicants.
- **🎟️ Membership Tiers:**
  - **ESNcard:** The standard paid physical card (with digital wallet representations).
  - **Free Pass:** A unique digital token (e.g., ESN Cyprus Pass) for verified members to use at local events.
  - **Guest Pass:** A new system enabling verified members (Free Pass holders) to invite friends and family to events.
- **🔄 ESNcard Backlog Management:** Robust background processing to manage ESNcard assignments and prevent failures
  without relying on payment gateway retries.
- **📱 Universal Scanner:** A built-in scanning tool that validates physical ESNcards, Free Passes, and Guest Passes in
  real-time.
- **🔒 Secure File Access:** Role-based access control for member documents (photos, IDs), ensuring privacy and security.
- **📂 Bulk Operations:** Efficient management of multiple records simultaneously via Views Bulk Operations.
- **⚙️ Flexible Configuration:** Deeply customizable settings to adapt to the specific needs of your ESN section.

### Integrations

- **💳 Payment Integration:** Seamless connection with [Stripe](https://stripe.com) for secure processing of ESNcard
  payments.
- **🔐 ESN Accounts Integration:** Connects with ESN Accounts for automated status verification of ESNers.
- **🆔 Didit Integration:** Connects with Didit for automatic online identity verification.
- **🎫 Weeztix Integration:** Connects with [Weeztix](https://weeztix.com/) to add ESNcards as promo codes for automatic
  discounts to holders. Optionally pushes coupon codes specifically for Free Passes.
- **👛 Google Wallet Integration:** Allows users to add their passes to
  their [Google Wallet](https://developers.google.com/wallet) for easy access.
- **🍎 Apple Wallet Integration:** Enables iOS users to save their passes to
  the [Apple Wallet](https://developer.apple.com/apple-wallet/).
- **📝 Google Sheets Integration:** Leverages the Google Sheets API to log all paid ESNcards to a spreadsheet in order to
  help integrate physical and online sales.

## Pass Management

The system accommodates three levels of passes to manage local events and memberships:

### Free Passes

- **What is it?** A unique, 32-character hexadecimal token assigned to verified, eligible members.
- **How it works:** When an application is approved, a Free Pass token is automatically generated. It acts as a "virtual
  card" stored in the system.
- **Usage:** Members can present their Free Pass (e.g., via digital wallet) at events. The **Scanner** feature
  (`/memberships/scan`) accepts these tokens just like physical ESNcard numbers. Scanning a Free Pass validates the
  member's status and updates their "last scanned" date.

### Guest Passes

- **What is it?** Passes that verified users (Free Pass holders) can generate to invite friends and family.
- **How it works:** Managed through the Membership Dashboard, this gives Free Pass holders the ability to securely bring
  guests to events while maintaining system integrity and tracking.

## Configuration

Navigate to the module configuration page (`/admin/config/system/esn_membership_manager`) to set up API keys, verify
services, and configure the Omnia modules.

## Minimum Requirements

- PHP: 8.2
- Drupal: 10
- A Stripe account

## Dependencies

- **[Omnia](https://github.com/esn-cy/omnia):** ^1.0
- **[ESN Accounts API](https://github.com/esn/esn_accounts_api):** ^1.1
- **[Drupal Core](https://www.drupal.org/):** ^10
- **[Drupal Simple Auth](https://www.drupal.org/project/simple_oauth):** ^6.1

*For the complete and exhaustive list of dependencies, please refer to the `composer.json` file.*

## License

This project is under the [Apache 2.0](./LICENSE) License.
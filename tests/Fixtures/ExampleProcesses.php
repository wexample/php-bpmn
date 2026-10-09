<?php

namespace Wexample\PhpBpmn\Tests\Fixtures;

use Wexample\PhpBpmn\Class\Builder\ProcessBuilder;
use Wexample\PhpBpmn\Class\Element\Definitions;
use Wexample\PhpBpmn\Enum\TimerKind;

/**
 * The example processes of the package, built in code; `examples/*.bpmn`
 * are written from them (ExamplesTest). Real cases, anonymized: a rental
 * agency — moving a tenant in, a repair, a visit —, an advisory firm; an
 * editorial review; and, to try a screen on a large one, a ministry of
 * finance paying a spending.
 */
final class ExampleProcesses
{
    /**
     * @return array<string, Definitions>
     */
    public static function all(): array
    {
        return [
            'tenant-move-in' => self::tenantMoveIn(),
            'maintenance-request' => self::maintenanceRequest(),
            'engagement-letter' => self::engagementLetter(),
            'article-review' => self::articleReview(),
            'visit-booking' => self::visitBooking(),
            'public-expenditure' => self::publicExpenditure(),
        ];
    }

    /**
     * From the applicant the landlord accepted to the keys handed over: the
     * lease signed while the holding deposit is paid, the guarantee
     * registered — reminded every five days —, the check-in inspection.
     */
    public static function tenantMoveIn(): Definitions
    {
        return ProcessBuilder::create('tenant_move_in', 'Tenant move-in')
            ->documentation('A rental agency moving a new tenant in: lease, holding deposit, rental guarantee, check-in inspection, keys.')
            ->lane('agent', 'Agent', ['role' => 'ROLE_AGENT'])
            ->lane('tenant', 'Tenant', ['role' => 'ROLE_TENANT'])
            ->lane('inspector', 'Inspector', ['role' => 'ROLE_INSPECTOR'])
            ->lane('platform', 'Platform')
            ->message('lease_signature_requested', 'Lease signature requested')
            ->message('lease_signed', 'Lease signed')
            ->message('deposit_paid', 'Holding deposit paid')
            ->startEvent('applicant_accepted', 'Applicant accepted by the landlord', 'agent')
            ->serviceTask('generate_lease', 'Generate the lease', 'platform')
            ->parallelGateway('lease_and_deposit', null, 'platform')
            ->sendTask('send_lease', 'Send the lease for signature', 'platform', 'lease_signature_requested')
            ->messageCatchEvent('lease_signed_by_all', 'Lease signed by all parties', 'lease_signed', 'platform')
            ->parallelGateway('lease_and_deposit_done', null, 'platform')
            ->from('lease_and_deposit')
            ->userTask('pay_deposit', 'Pay the holding deposit', 'tenant')
            ->connectTo('lease_and_deposit_done')
            ->serviceTask('call_for_funds', 'Send the call for funds', 'platform')
            ->userTask('register_guarantee', 'Register the rental guarantee', 'agent')
            ->userTask('schedule_inspection', 'Schedule the check-in inspection', 'agent')
            ->userTask('inspect', 'Inspect and upload the report', 'inspector')
            ->manualTask('hand_over_keys', 'Hand over the keys', 'agent')
            ->endEvent('moved_in', 'Tenant moved in', 'agent')
            ->boundaryTimer('guarantee_overdue', 'Five days without guarantee', 'register_guarantee', 'P5D')
            ->sendTask('remind_guarantee', 'Remind the tenant of the guarantee', 'platform')
            ->connectTo('register_guarantee')
            ->property('remind_guarantee', 'notification', 'rental.guarantee_reminder')
            ->build();
    }

    /**
     * A repair reported by the tenant: above 200 € of quote, the landlord
     * decides — reminded after three days —; below, the agent does and tells
     * the landlord.
     */
    public static function maintenanceRequest(): Definitions
    {
        return ProcessBuilder::create('maintenance_request', 'Maintenance request')
            ->documentation('A rental agency handling a repair: quote, approval by the landlord above a threshold, works.')
            ->lane('tenant', 'Tenant', ['role' => 'ROLE_TENANT'])
            ->lane('agent', 'Agent', ['role' => 'ROLE_AGENT'])
            ->lane('landlord', 'Landlord', ['role' => 'ROLE_LANDLORD'])
            ->lane('platform', 'Platform')
            ->message('works_done', 'Works done')
            ->startEvent('repair_reported', 'Repair reported', 'tenant')
            ->userTask('get_quote', 'Get a quote', 'agent')
            ->exclusiveGateway('quote_amount', 'Quote above 200 €?', 'agent')
            ->sendTask('ask_landlord', 'Ask the landlord to approve', 'platform', condition: "variables['quote'] > 200")
            ->userTask('landlord_decides', 'Approve or refuse the quote', 'landlord')
            ->exclusiveGateway('landlord_answer', 'Approved?', 'landlord')
            ->endEvent('refused', 'Repair refused', 'landlord', condition: "not variables['approved']")
            ->from('landlord_answer', default: true)
            ->userTask('order_works', 'Order the works', 'agent')
            ->messageCatchEvent('works_done_received', 'Works done', 'works_done', 'agent')
            ->endEvent('repaired', 'Repaired', 'agent')
            ->from('quote_amount', default: true)
            ->userTask('agent_decides', 'Decide on the quote', 'agent')
            ->sendTask('tell_landlord', 'Tell the landlord', 'platform')
            ->connectTo('order_works')
            ->boundaryTimer('landlord_silent', 'Three days without answer', 'landlord_decides', 'P3D')
            ->sendTask('remind_landlord', 'Remind the landlord', 'platform')
            ->connectTo('landlord_decides')
            ->property('ask_landlord', 'notification', 'rental.quote_approval')
            ->property('remind_landlord', 'notification', 'rental.quote_approval_reminder')
            ->build();
    }

    /**
     * An engagement letter of an advisory firm: composed, signed in order by
     * the manager, the partner and the client — reminded weekly —, archived,
     * then renewed after one year for a high risk, three otherwise.
     */
    public static function engagementLetter(): Definitions
    {
        return ProcessBuilder::create('engagement_letter', 'Engagement letter')
            ->documentation('An advisory firm writing an engagement letter for a client: composition, ordered signatures, archive, renewal by risk.')
            ->lane('manager', 'Manager', ['role' => 'ROLE_MANAGER'])
            ->lane('partner', 'Partner', ['role' => 'ROLE_PARTNER'])
            ->lane('client', 'Client', ['role' => 'ROLE_CLIENT'])
            ->lane('platform', 'Platform')
            ->message('renewal_due', 'Renewal due')
            ->startEvent('client_identified', 'Client identified', 'manager')
            ->userTask('compose', 'Compose the letter section by section', 'manager')
            ->userTask('review', 'Review the whole letter', 'manager')
            ->serviceTask('render_pdf', 'Render the final document', 'platform')
            ->userTask('manager_signs', 'Sign as manager', 'manager')
            ->userTask('partner_signs', 'Sign as partner', 'partner')
            ->userTask('client_signs', 'Sign as client', 'client')
            ->serviceTask('archive', 'Archive the signed letter', 'platform')
            ->exclusiveGateway('risk', 'Risk level?', 'platform')
            ->timerEvent('one_year', 'One year', 'P1Y', 'platform', condition: "variables['risk'] == 'high'")
            ->exclusiveGateway('renewal', null, 'platform')
            ->sendTask('remind_renewal', 'Remind the manager and the partner', 'platform', 'renewal_due')
            ->userTask('renew', 'Validate the renewed letter', 'manager')
            ->endEvent('renewed', 'Letter renewed', 'manager')
            ->from('risk', default: true)
            ->timerEvent('three_years', 'Three years', 'P3Y', 'platform')
            ->connectTo('renewal')
            ->boundaryTimer('client_silent', 'A week without signature', 'client_signs', 'P7D')
            ->sendTask('remind_client', 'Remind the client to sign', 'platform')
            ->connectTo('client_signs')
            ->property('remind_renewal', 'notification', 'engagement.renewal_due')
            ->property('remind_client', 'notification', 'engagement.signature_reminder')
            ->build();
    }

    /**
     * An article of a team: written by a contributor, reviewed by an
     * administrator — reminded after two days —, published, or sent back.
     */
    public static function articleReview(): Definitions
    {
        return ProcessBuilder::create('article_review', 'Article review')
            ->documentation('An editorial team reviewing an article before it is published.')
            ->lane('author', 'Author', ['grant' => 'contributor'])
            ->lane('editor', 'Editor', ['grant' => 'administrator'])
            ->lane('platform', 'Platform')
            ->message('article_published', 'Article published')
            ->startEvent('submitted', 'Article submitted', 'author')
            ->userTask('review', 'Review the article', 'editor')
            ->exclusiveGateway('verdict', 'Approved?', 'editor')
            ->messageThrowEvent('announce', 'Announce the article', 'article_published', 'platform', condition: "variables['approved']")
            ->endEvent('published', 'Published', 'platform')
            ->from('verdict', default: true)
            ->userTask('revise', 'Revise the article', 'author')
            ->connectTo('review')
            ->boundaryTimer('review_late', 'Two days without review', 'review', 'P2D')
            ->sendTask('remind_editor', 'Remind the editor', 'platform')
            ->connectTo('review')
            ->property('announce', 'notification', 'article.published')
            ->property('remind_editor', 'notification', 'article.review_reminder')
            ->build();
    }

    /**
     * A lead asking to visit a property: qualified, offered slots — reminded
     * after two days without an answer, given up after a second silence —,
     * then reminded the day before and the hour before the visit, at times
     * the platform computes from the slot chosen.
     */
    public static function visitBooking(): Definitions
    {
        return ProcessBuilder::create('visit_booking', 'Visit booking')
            ->documentation('A rental agency booking a visit: lead qualified, slot chosen, reminders the day and the hour before.')
            ->lane('prospect', 'Prospect', ['role' => 'ROLE_PROSPECT'])
            ->lane('agent', 'Agent', ['role' => 'ROLE_AGENT'])
            ->lane('platform', 'Platform')
            ->message('slot_chosen', 'Slot chosen')
            ->startEvent('lead_received', 'Visit request received', 'prospect')
            ->userTask('qualify', 'Qualify the lead', 'agent')
            ->sendTask('offer_slots', 'Offer visit slots', 'platform')
            ->eventBasedGateway('answer', 'Answer?', 'platform')
            ->messageCatchEvent('slot_chosen_received', 'Slot chosen', 'slot_chosen', 'platform')
            ->serviceTask('plan_reminders', 'Plan the reminders', 'platform')
            ->timerEvent('day_before', 'The day before', "\${variables['remind_day_before']}", 'platform', TimerKind::DATE)
            ->sendTask('remind_day_before', 'Remind by text message', 'platform')
            ->timerEvent('hour_before', 'The hour before', "\${variables['remind_hour_before']}", 'platform', TimerKind::DATE)
            ->sendTask('remind_hour_before', 'Remind by push', 'platform')
            ->manualTask('visit', 'Visit the property', 'agent')
            ->endEvent('visited', 'Property visited', 'agent')
            ->from('answer')
            ->timerEvent('no_answer', 'Two days without answer', 'P2D', 'platform')
            ->exclusiveGateway('relaunched', 'Reminded already?', 'platform')
            ->endEvent('given_up', 'Lead given up', 'platform', condition: "variables['relaunched']")
            ->from('relaunched', default: true)
            ->serviceTask('relaunch', 'Relaunch the prospect', 'platform')
            ->connectTo('offer_slots')
            ->property('remind_day_before', 'notification', 'visit.reminder_day_before')
            ->property('remind_hour_before', 'notification', 'visit.reminder_hour_before')
            ->build();
    }

    /**
     * A spending of a ministry of finance, from the need to the payment, as
     * public accounting runs it: the credits checked and reserved — a
     * transfer asked when they run short —, the financial controller's visa
     * above a threshold — reminded after fifteen days —, a call for tenders
     * while the legal commitment is recorded, the service certified —
     * reminded after ten days —, the invoice awaited — claimed after sixty —
     * and matched with the order, the payment ordered, then checked and paid
     * by the public accountant, late-payment interest computed past the legal
     * period. The large one of the examples, with seven lanes and loops back.
     */
    public static function publicExpenditure(): Definitions
    {
        return ProcessBuilder::create('public_expenditure', 'Public expenditure')
            ->documentation('A ministry of finance carrying a spending from the need to the payment: credits checked and reserved, a credit transfer when they run short, the financial controller\'s visa above a threshold, a call for tenders, the service certified, the invoice matched with the order, the payment ordered, then checked and paid by the public accountant.')
            ->lane('department', 'Requesting department', ['role' => 'ROLE_DEPARTMENT_OFFICER'])
            ->lane('budget', 'Budget office', ['role' => 'ROLE_BUDGET_OFFICER'])
            ->lane('controller', 'Financial controller', ['role' => 'ROLE_FINANCIAL_CONTROLLER'])
            ->lane('procurement', 'Procurement', ['role' => 'ROLE_PROCUREMENT_OFFICER'])
            ->lane('supplier', 'Supplier', ['role' => 'ROLE_SUPPLIER'])
            ->lane('accountant', 'Public accountant', ['role' => 'ROLE_PUBLIC_ACCOUNTANT'])
            ->lane('platform', 'Platform')
            ->message('award_notified', 'Award notified')
            ->message('invoice_received', 'Invoice received')
            ->message('payment_sent', 'Payment sent')
            ->startEvent('need_expressed', 'Spending need expressed', 'department')
            ->userTask('draft_request', 'Draft the commitment request', 'department')
            ->serviceTask('check_credits', 'Check the available credits', 'platform')
            ->exclusiveGateway('credits', 'Credits available?', 'platform')
            ->userTask('request_transfer', 'Request a credit transfer', 'budget', condition: "not variables['credits_available']")
            ->exclusiveGateway('transfer', 'Transfer granted?', 'budget')
            ->endEvent('abandoned', 'Request abandoned', 'budget', condition: "not variables['transfer_granted']")
            ->from('transfer', default: true)
            ->connectTo('check_credits')
            ->from('credits', default: true)
            ->serviceTask('reserve_credits', 'Reserve the credits', 'platform')
            ->exclusiveGateway('threshold', 'Above the control threshold?', 'platform')
            ->userTask('control', 'Examine the commitment', 'controller', condition: "variables['amount'] > 40000")
            ->exclusiveGateway('visa', 'Visa granted?', 'controller')
            ->userTask('revise_request', 'Revise the request', 'department', condition: "not variables['visa']")
            ->serviceTask('release_credits', 'Release the reserved credits', 'platform')
            ->connectTo('check_credits')
            ->from('visa', default: true)
            ->exclusiveGateway('committed', null, 'platform')
            ->from('threshold', default: true)
            ->connectTo('committed')
            ->from('committed')
            ->parallelGateway('launch', null, 'procurement')
            ->serviceTask('record_commitment', 'Record the legal commitment', 'platform')
            ->parallelGateway('launched', null, 'procurement')
            ->from('launch')
            ->userTask('publish_tender', 'Publish the call for tenders', 'procurement')
            ->timerEvent('consultation', 'Thirty days of consultation', 'P30D', 'procurement')
            ->userTask('select_offer', 'Select the winning offer', 'procurement')
            ->sendTask('notify_award', 'Notify the award', 'platform', 'award_notified')
            ->connectTo('launched')
            ->from('launched')
            ->manualTask('deliver', 'Deliver the goods or the service', 'supplier')
            ->userTask('certify', 'Certify the service done', 'department')
            ->exclusiveGateway('invoice_expected', null, 'platform')
            ->eventBasedGateway('invoice_wait', 'Invoice?', 'platform')
            ->messageCatchEvent('invoice_in', 'Invoice received', 'invoice_received', 'platform')
            ->serviceTask('match_invoice', 'Match the invoice with the order and the certification', 'platform')
            ->exclusiveGateway('matched', 'Invoice matched?', 'platform')
            ->userTask('dispute', 'Dispute the invoice', 'accountant', condition: "not variables['matched']")
            ->sendTask('ask_credit_note', 'Ask the supplier for a credit note', 'platform')
            ->connectTo('invoice_expected')
            ->from('matched', default: true)
            ->userTask('order_payment', 'Order the payment', 'budget')
            ->userTask('check_payment', 'Check the payment order', 'accountant')
            ->exclusiveGateway('accepted', 'Payment order rejected?', 'accountant')
            ->userTask('correct_order', 'Correct the payment order', 'budget', condition: "variables['rejected']")
            ->connectTo('check_payment')
            ->from('accepted', default: true)
            ->serviceTask('pay', 'Send the bank transfer', 'platform')
            ->messageThrowEvent('notify_paid', 'Tell the supplier', 'payment_sent', 'platform')
            ->serviceTask('close_commitment', 'Close the commitment', 'platform')
            ->endEvent('paid', 'Spending paid', 'accountant')
            ->from('invoice_wait')
            ->timerEvent('no_invoice', 'Sixty days without invoice', 'P60D', 'platform')
            ->sendTask('claim_invoice', 'Claim the invoice', 'platform')
            ->connectTo('invoice_expected')
            ->boundaryTimer('control_late', 'Fifteen days without visa', 'control', 'P15D')
            ->sendTask('remind_controller', 'Remind the controller', 'platform')
            ->connectTo('control')
            ->boundaryTimer('certification_late', 'Ten days without certification', 'certify', 'P10D')
            ->sendTask('remind_department', 'Remind the department', 'platform')
            ->connectTo('certify')
            ->boundaryTimer('payment_period', 'Legal payment period exceeded', 'check_payment', 'P30D')
            ->serviceTask('late_interest', 'Compute the late-payment interest', 'platform')
            ->connectTo('check_payment')
            ->property('notify_award', 'notification', 'expenditure.award_notified')
            ->property('notify_paid', 'notification', 'expenditure.payment_sent')
            ->property('claim_invoice', 'notification', 'expenditure.invoice_claim')
            ->property('ask_credit_note', 'notification', 'expenditure.credit_note_request')
            ->property('remind_controller', 'notification', 'expenditure.visa_reminder')
            ->property('remind_department', 'notification', 'expenditure.certification_reminder')
            ->build();
    }
}

<?php

namespace Wexample\PhpBpmn\Tests\Fixtures;

use Wexample\PhpBpmn\Class\Builder\ProcessBuilder;
use Wexample\PhpBpmn\Class\Element\Definitions;

/**
 * The example processes of the package, built in code; `examples/*.bpmn`
 * are written from them (ExamplesTest). Real cases, anonymized: a rental
 * agency, an advisory firm; and an editorial review.
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
}

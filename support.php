<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
$user = current_user();

$ci = [
    'email'    => get_platform_setting('contact_email')    ?: MAIL_FROM,
    'phone'    => get_platform_setting('contact_phone')    ?: '',
    'whatsapp' => get_platform_setting('contact_whatsapp') ?: '',
    'hours'    => get_platform_setting('contact_hours')    ?: 'Monday – Saturday, 8:00 AM – 6:00 PM GMT',
];
$waLink = $ci['whatsapp'] ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $ci['whatsapp']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Help &amp; Support — <?php echo sanitize(APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .sup-shell  { max-width: 800px; margin: 0 auto; padding: 24px 16px 60px; }
        .sup-shell h1 { font-size: 1.6rem; font-weight: 900; margin: 0 0 6px; }
        .sup-section  { margin-bottom: 36px; }
        .sup-section h2 { font-size: 1rem; font-weight: 800; border-bottom: 2px solid var(--border,#e5e7eb); padding-bottom: 8px; margin: 0 0 16px; }
        .faq-item   { border: 1px solid var(--border,#e5e7eb); border-radius: 10px; margin-bottom: 8px; overflow: hidden; }
        .faq-q      { display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; cursor: pointer; font-weight: 700; font-size: .9rem; background: var(--surface,#fff); gap: 10px; }
        .faq-q:hover { background: var(--surface-muted,#f8fafc); }
        .faq-q .arrow { font-size: .75rem; color: var(--muted,#6b7280); flex-shrink: 0; transition: transform .2s; }
        .faq-a      { display: none; padding: 0 16px 14px; font-size: .88rem; color: #374151; line-height: 1.7; }
        .faq-a ul   { margin: 6px 0; padding-left: 18px; }
        .faq-item.open .faq-a   { display: block; }
        .faq-item.open .arrow   { transform: rotate(180deg); }
        .contact-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
        .contact-card  { background: var(--surface,#fff); border: 1px solid var(--border,#e5e7eb); border-radius: 12px; padding: 18px 16px; text-align: center; text-decoration: none; color: inherit; transition: box-shadow .15s; }
        .contact-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }
        .contact-card .ci { font-size: 1.6rem; margin-bottom: 8px; }
        .contact-card h3  { font-size: .9rem; font-weight: 800; margin: 0 0 4px; }
        .contact-card p   { font-size: .78rem; color: var(--muted,#6b7280); margin: 0; }
    </style>
</head>
<body <?php echo $user ? 'class="has-bottom-nav"' : ''; ?>>
<header class="app-topbar">
    <a href="<?php echo $user ? 'community.php' : 'index.php'; ?>" class="button button-secondary button-small">← Back</a>
    <span class="brand"><?php echo sanitize(APP_NAME); ?></span>
</header>

<div class="sup-shell">
    <h1>Help &amp; Support</h1>
    <p style="font-size:.93rem;color:var(--muted,#6b7280);margin:0 0 28px;">Find answers to common questions below, or reach out and we'll be glad to help.</p>

    <!-- Account & Registration -->
    <div class="sup-section">
        <h2>Account &amp; Registration</h2>

        <div class="faq-item">
            <div class="faq-q">How do I create an account? <span class="arrow">▼</span></div>
            <div class="faq-a">Click <strong>Register</strong> from the home page. Choose whether you are registering as a <em>Customer</em> (to post jobs) or a <em>Worker</em> (to offer services). Workers must supply a valid ID during registration. After registering, verify your email address to activate all features.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">Why do I need to verify my email? <span class="arrow">▼</span></div>
            <div class="faq-a">Email verification confirms that your account belongs to you and allows us to send you important notifications (job updates, payment confirmations, application status). Without verification, some features — including posting jobs — are restricted.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">I forgot my password. What do I do? <span class="arrow">▼</span></div>
            <div class="faq-a">Go to the <a href="forgot_password.php">Forgot Password</a> page and enter your registered email address. We will send you a 6-digit OTP code. Enter the code on the next screen, then set a new password. The OTP expires in 15 minutes.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">Can I switch between Customer and Worker? <span class="arrow">▼</span></div>
            <div class="faq-a">Yes. Go to <strong>Settings → Role</strong>. Customers can become Workers by completing ID verification and adding skills. Workers can switch to Customer mode instantly and switch back later without losing their profile or skills.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How do I delete my account? <span class="arrow">▼</span></div>
            <div class="faq-a">Go to <strong>Settings → Account</strong> and scroll to the bottom. You can close your account by entering your password and confirming. Your profile will be deactivated. Financial records are retained as required by Ghanaian law.</div>
        </div>
    </div>

    <!-- Jobs & Services -->
    <div class="sup-section">
        <h2>Jobs &amp; Services</h2>

        <div class="faq-item">
            <div class="faq-q">How do I post a job? <span class="arrow">▼</span></div>
            <div class="faq-a">Log in and tap <strong>Post a Job</strong> from your dashboard or the home page. Fill in the title, description, category, location, and budget. Choose whether you want direct payment or escrow. Once submitted, your job goes for admin review before going live.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">My job was rejected. What now? <span class="arrow">▼</span></div>
            <div class="faq-a">You will receive an in-app notification and email explaining the reason. Go to <strong>My Jobs → Rejected</strong>, click <em>Edit</em>, make the requested changes, and resubmit. You will not be charged again for the same listing.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How do workers apply for jobs? <span class="arrow">▼</span></div>
            <div class="faq-a">Workers browse open jobs on the Jobs page and tap <strong>Apply</strong> on any listing. The application goes to the customer and a manager for review. If approved, the worker is assigned to the job.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">What is escrow payment? <span class="arrow">▼</span></div>
            <div class="faq-a">Escrow means the customer pays upfront via Paystack and the funds are held securely by the platform. The money is only released to the worker once the customer confirms the job is complete. If the customer does not confirm within 7 days of the job being marked done, funds are released automatically. This protects both parties.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How do I cancel a job? <span class="arrow">▼</span></div>
            <div class="faq-a">Open the job from your dashboard and use the <strong>Cancel</strong> option. You will be asked to provide a reason. If escrow funds are involved, cancellation and refund eligibility are subject to our <a href="terms.php">Terms of Service</a>.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">What happens when a job is complete? <span class="arrow">▼</span></div>
            <div class="faq-a">The worker marks the job complete and can optionally upload photos as evidence. The customer reviews and confirms completion — this triggers payment release. Both parties can then rate each other.</div>
        </div>
    </div>

    <!-- Find Workers -->
    <div class="sup-section">
        <h2>Finding Workers</h2>

        <div class="faq-item">
            <div class="faq-q">How do I find a worker near me? <span class="arrow">▼</span></div>
            <div class="faq-a">Go to <a href="find_workers.php">Find Workers</a> and use the search and filter options to narrow by skill, category, location, and availability. You can also tap <strong>Find workers near me</strong> to sort by distance using your GPS location.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">What does the Verified badge mean? <span class="arrow">▼</span></div>
            <div class="faq-a">A Verified badge means an admin has reviewed and confirmed the worker's government-issued ID (Ghana Card, passport, or equivalent). It does not guarantee the quality of work, but it confirms the worker's identity is genuine.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How do worker ratings work? <span class="arrow">▼</span></div>
            <div class="faq-a">After a job is completed and confirmed, both the customer and worker can leave a star rating and written review. Ratings are visible on the worker's public profile and factor into their leaderboard ranking.</div>
        </div>
    </div>

    <!-- Events -->
    <div class="sup-section">
        <h2>Community Events</h2>

        <div class="faq-item">
            <div class="faq-q">How do I submit a community event? <span class="arrow">▼</span></div>
            <div class="faq-a">Log in and go to <strong>My Events</strong> (from the Community section). Fill in the event title, description, date, time, venue, and organiser details. You may also add a featured image and ticket/registration link. The event is reviewed by an admin before being published.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">My event was rejected. Can I fix it and resubmit? <span class="arrow">▼</span></div>
            <div class="faq-a">Yes. You will receive a notification with the reason. On the <strong>My Events</strong> page, find the rejected event in the sidebar, click <em>Edit</em>, update accordingly, and save. It will go back for review at no extra cost.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">Is there a fee to post an event? <span class="arrow">▼</span></div>
            <div class="faq-a">A publishing fee may apply depending on the platform's current settings. If a fee is enabled, you will be informed before submitting and taken to a Paystack checkout. Check the current fee on the <a href="my_events.php">My Events</a> page.</div>
        </div>
    </div>

    <!-- Funerals -->
    <div class="sup-section">
        <h2>Funeral Announcements</h2>

        <div class="faq-item">
            <div class="faq-q">How do I post a funeral announcement? <span class="arrow">▼</span></div>
            <div class="faq-a">Log in and go to <strong>My Funeral Announcements</strong> (from the Community section). Fill in the deceased's details, funeral schedule (wake keeping, burial, thanksgiving), venue, and organiser contact. You may also upload a photograph of the deceased and a funeral poster. The announcement is reviewed before going live.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">Can I update an announcement after submission? <span class="arrow">▼</span></div>
            <div class="faq-a">If the announcement has been rejected, you can edit and resubmit it from <strong>My Funeral Announcements</strong>. Once an announcement has been approved and published, contact us directly via <a href="contact.php">the contact form</a> and we will update it on your behalf.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">Is there a fee to post a funeral announcement? <span class="arrow">▼</span></div>
            <div class="faq-a">A fee may apply depending on the platform's current settings. You will be informed of the amount before submitting. Fees go toward the cost of running and moderating the platform.</div>
        </div>
    </div>

    <!-- News & Articles -->
    <div class="sup-section">
        <h2>News &amp; Articles</h2>

        <div class="faq-item">
            <div class="faq-q">How do I submit a news article? <span class="arrow">▼</span></div>
            <div class="faq-a">Log in and go to <strong>My Articles</strong> (from the Community section). Write a title, an optional teaser summary, and the full article body. You may add a featured image. All submissions are reviewed by an admin for accuracy and community relevance before publication.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">My article was rejected. What should I do? <span class="arrow">▼</span></div>
            <div class="faq-a">A notification will be sent with the rejection reason. Find the article in the sidebar on <strong>My Articles</strong>, click <em>Edit</em>, make the necessary changes, and resubmit. No additional fee applies for the same submission.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">What kind of articles are acceptable? <span class="arrow">▼</span></div>
            <div class="faq-a">We welcome articles about community life, local news, events, development projects, cultural updates, and matters relevant to the Akuapem area and Ghana. Articles must be factually accurate, respectful, and free of hate speech, misinformation, or illegal content.</div>
        </div>
    </div>

    <!-- Delivery Services -->
    <div class="sup-section">
        <h2>Delivery Services</h2>

        <div class="faq-item">
            <div class="faq-q">How do I request a delivery? <span class="arrow">▼</span></div>
            <div class="faq-a">Log in and tap <strong>Request Delivery</strong> from the Delivery Services page or the home screen. Fill in the pickup location, drop-off location, item description, category, and your preferred date and payment method. You can optionally paste a Google Maps link for each location to help the rider. Your request will be reviewed by an admin (or auto-approved if you meet the trust criteria) before riders can see it.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How long does admin review take? <span class="arrow">▼</span></div>
            <div class="faq-a">Most requests are reviewed within a few hours. Customers who have completed 10 or more successful deliveries and have an account older than 60 days are automatically approved without waiting for admin review. You will receive an in-app notification as soon as your request is approved or if it is rejected with a reason.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How do I select a delivery rider? <span class="arrow">▼</span></div>
            <div class="faq-a">Once your request is approved, verified riders can apply. You will see a list of applicants on the request detail page — each showing the rider's vehicle type, rating, completed deliveries, and their offered fee. Tap <strong>Select This Rider</strong> to assign the job. All other applicants are notified that the position is filled.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How do I track my delivery? <span class="arrow">▼</span></div>
            <div class="faq-a">Open the delivery from your <strong>Delivery Services</strong> dashboard. You will see a live status timeline: Pending → Accepted → Picked Up → In Transit → Delivered. You receive an in-app notification and email at each stage. If a Google Maps link was provided for your drop-off, you can share it directly with the rider via the detail page.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">Can I cancel a delivery? <span class="arrow">▼</span></div>
            <div class="faq-a">Yes, you can cancel a delivery that is still in <em>Pending</em> or <em>Approved</em> status before a rider is assigned. Once a rider has been assigned and started the trip, cancellation is no longer available through the app — contact support if you need urgent assistance.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How do I become a delivery rider? <span class="arrow">▼</span></div>
            <div class="faq-a">Go to <strong>Delivery Services → Become a Delivery Agent</strong>. Register your vehicle details, upload a valid ID, describe your service area, and submit for admin approval. Once approved, you can set your availability, browse open delivery requests, apply for jobs, and start earning. You can also apply for a Verified Rider badge for extra trust.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">What is a Verified Rider badge? <span class="arrow">▼</span></div>
            <div class="faq-a">The Verified Rider badge is awarded to agents who submit additional verification documents — a selfie photo, Ghana Card, and optionally a driver's licence and vehicle registration. An admin reviews and approves the documents. Verified riders appear higher in search results and are more likely to be selected by customers.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">What do Premium and Sponsored rider listings mean? <span class="arrow">▼</span></div>
            <div class="faq-a"><strong>Premium Rider</strong> subscriptions (monthly, quarterly, or yearly) place the rider above basic riders in search results and add a purple Premium badge. <strong>Sponsored</strong> listings place the rider at the very top of all results and add a gold Sponsored badge. Both features are optional and available for a configurable fee from your Agent Dashboard.</div>
        </div>
    </div>

    <!-- Marketplace -->
    <div class="sup-section">
        <h2>Marketplace &amp; Shopping</h2>

        <div class="faq-item">
            <div class="faq-q">How do I buy a product on the marketplace? <span class="arrow">▼</span></div>
            <div class="faq-a">Browse the <a href="marketplace.php">Marketplace</a> by category, keyword, price range, or condition. When you find a product you want, open the product page and tap <strong>Add to Cart</strong>. When ready, go to your Cart, review the items, and tap <strong>Checkout</strong>. Enter your delivery address (you can paste a Google Maps link to help the seller locate you), choose a payment method, and place your order. You will receive a receipt by email and in the app.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How do I open a shop and sell products? <span class="arrow">▼</span></div>
            <div class="faq-a">Log in and go to <strong>My Shop</strong> from the profile menu (or visit seller_dashboard.php directly). Click <em>Create Your Shop</em>, fill in your shop name, description, contact details, and region. Then go to the <strong>Products</strong> tab and click <strong>+ Add Product</strong>. Fill in the product details, upload up to 5 photos, set your price and stock, and submit for admin approval. Approved products go live immediately.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How long does product approval take? <span class="arrow">▼</span></div>
            <div class="faq-a">Most products are reviewed within a few hours. You will receive an in-app notification when your product is approved or if it is rejected with a reason. You can edit and resubmit a rejected product without any additional fee.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How are marketplace orders delivered? <span class="arrow">▼</span></div>
            <div class="faq-a">Delivery is arranged between you and the seller. When a seller marks an order <em>Ready for Delivery</em>, a delivery request is automatically created on the Delivery Services platform and nearby riders are notified. Alternatively, a seller may arrange their own delivery or request the buyer to pick up in person — check the product listing or contact the seller to confirm.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">What do I do if I have a problem with my order? <span class="arrow">▼</span></div>
            <div class="faq-a">First, try to contact the seller directly through the messaging or phone number on the shop page. If the issue cannot be resolved, contact us via the <a href="contact.php">contact form</a> with your order number, a description of the problem, and any photos if relevant. Our team mediates marketplace disputes and will respond within 2 business days.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How can I make my shop or products more visible? <span class="arrow">▼</span></div>
            <div class="faq-a">From your <strong>Seller Dashboard</strong>, click <strong>⚡ Boost</strong> on any approved product, or go to the <em>Boost</em> button at the top of your product list. You can choose <em>Featured</em> (appears above standard listings) or <em>Sponsored</em> (top placement with a gold badge) for your products and shop, in 7-day or 30-day packages. Boost orders go to admin for activation after payment confirmation.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">Can I save products to look at later? <span class="arrow">▼</span></div>
            <div class="faq-a">Yes. On any product page, tap the ❤️ heart button to save it to your <strong>Saved Products</strong> list. Access your saved items from the profile menu → Saved Products at any time. You can add saved items directly to cart from the Saved Products page.</div>
        </div>
    </div>

    <!-- Payments -->
    <div class="sup-section">
        <h2>Payments &amp; Receipts</h2>

        <div class="faq-item">
            <div class="faq-q">What payment methods are accepted? <span class="arrow">▼</span></div>
            <div class="faq-a">All payments are processed via <strong>Paystack</strong>, which accepts mobile money (MTN, Vodafone, AirtelTigo), debit/credit cards, and bank transfers where available. <?php echo sanitize(APP_NAME); ?> never stores your card or mobile money details.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">Where do I find my payment receipts? <span class="arrow">▼</span></div>
            <div class="faq-a">Go to <strong>My Payments</strong> from your dashboard. Each payment entry has a <em>View Receipt</em> option you can print or save as a PDF.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">I was charged but the job was never approved. Will I get a refund? <span class="arrow">▼</span></div>
            <div class="faq-a">Job posting fees are generally non-refundable if the job is rejected (see <a href="terms.php">Terms of Service</a>). However, if you believe you were charged in error, contact us via the form below and we will investigate within 2 business days.</div>
        </div>
    </div>

    <!-- Disputes -->
    <div class="sup-section">
        <h2>Disputes &amp; Safety</h2>

        <div class="faq-item">
            <div class="faq-q">How do I raise a dispute? <span class="arrow">▼</span></div>
            <div class="faq-a">Open the relevant job from your dashboard and tap <strong>File a Dispute</strong>. Describe the issue and attach any relevant evidence. Our admin team will review submissions from both parties and issue a decision. Dispute decisions on escrow funds are final.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">A user is harassing me. What do I do? <span class="arrow">▼</span></div>
            <div class="faq-a">Block the user in chat if possible and report the behaviour using the <a href="contact.php">contact form</a>. Include the username and a description of the incident. We take harassment seriously and will act promptly.</div>
        </div>

        <div class="faq-item">
            <div class="faq-q">How do I report a fake listing? <span class="arrow">▼</span></div>
            <div class="faq-a">Contact us using the <a href="contact.php">contact form</a> and include the job ID or URL of the suspicious listing. Our team reviews all reports and removes fraudulent content quickly.</div>
        </div>
    </div>

    <!-- Contact -->
    <div class="sup-section">
        <h2>Still Need Help?</h2>
        <p style="font-size:.9rem;color:#374151;margin:0 0 16px;">Our support team is available <?php echo sanitize($ci['hours']); ?>.</p>
        <div class="contact-cards">
            <a href="contact.php" class="contact-card">
                <div class="ci">✉️</div>
                <h3>Contact Form</h3>
                <p>Fill in the form and we'll reply within 1–2 business days.</p>
            </a>
            <a href="mailto:<?php echo sanitize($ci['email']); ?>" class="contact-card">
                <div class="ci">📧</div>
                <h3>Email Us</h3>
                <p><?php echo sanitize($ci['email']); ?></p>
            </a>
            <?php if ($waLink): ?>
            <a href="<?php echo sanitize($waLink); ?>" target="_blank" rel="noopener" class="contact-card">
                <div class="ci">💬</div>
                <h3>WhatsApp</h3>
                <p><?php echo sanitize($ci['whatsapp']); ?></p>
            </a>
            <?php endif; ?>
            <?php if ($ci['phone']): ?>
            <a href="tel:<?php echo sanitize(preg_replace('/[^0-9+]/', '', $ci['phone'])); ?>" class="contact-card">
                <div class="ci">📞</div>
                <h3>Call Us</h3>
                <p><?php echo sanitize($ci['phone']); ?></p>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <p class="meta" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border,#e5e7eb);">
        <a href="about.php">About</a> &nbsp;·&nbsp;
        <a href="terms.php">Terms of Service</a> &nbsp;·&nbsp;
        <a href="privacy.php">Privacy Policy</a> &nbsp;·&nbsp;
        <a href="contact.php">Contact</a>
    </p>
</div>

<script>
document.querySelectorAll('.faq-q').forEach(function(q) {
    q.addEventListener('click', function() {
        var item = this.closest('.faq-item');
        var isOpen = item.classList.contains('open');
        document.querySelectorAll('.faq-item.open').forEach(function(el) { el.classList.remove('open'); });
        if (!isOpen) item.classList.add('open');
    });
});
</script>

<?php if ($user): $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>

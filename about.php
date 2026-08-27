<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>About — <?php echo sanitize(APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .about-shell { max-width: 800px; margin: 0 auto; padding: 24px 16px 60px; }
        .about-hero {
            background: linear-gradient(135deg, rgba(15,118,110,.78) 0%, rgba(6,95,70,.86) 100%),
                        url('assets/images/heroes/hero-about.jpg') center/cover no-repeat;
            color: #fff;
            border-radius: 16px;
            padding: 56px 28px 48px;
            margin-bottom: 32px;
            text-align: center;
            overflow: hidden;
        }
        .about-hero h1 { font-size: clamp(1.6rem, 5vw, 2.2rem); font-weight: 900; margin: 0 0 12px; text-shadow: 0 2px 8px rgba(0,0,0,.25); }
        .about-hero p  { font-size: .98rem; color: #a7f3d0; margin: 0; line-height: 1.75; text-shadow: 0 1px 4px rgba(0,0,0,.2); }
        .about-section { margin-bottom: 36px; }
        .about-section h2 { font-size: 1.05rem; font-weight: 800; border-bottom: 2px solid var(--border,#e5e7eb); padding-bottom: 8px; margin: 0 0 14px; }
        .about-section p, .about-section li { font-size: .93rem; color: #374151; line-height: 1.75; }
        .about-section ul { padding-left: 20px; margin: 8px 0; }
        .service-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
        .service-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 14px; padding: 18px 16px; }
        .service-card .icon { font-size: 1.8rem; margin-bottom: 8px; }
        .service-card h3  { font-size: .92rem; font-weight: 800; margin: 0 0 5px; }
        .service-card p   { font-size: .8rem; color: var(--muted, #6b7280); margin: 0; line-height: 1.55; }
        .how-steps { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
        .step-card  { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 16px 14px; }
        .step-card .num { font-size: .72rem; font-weight: 800; color: #059669; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; }
        .step-card h4   { font-size: .9rem; font-weight: 800; margin: 0 0 5px; color: #065f46; }
        .step-card p    { font-size: .8rem; color: #374151; margin: 0; line-height: 1.55; }
        .values-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
        .value-card { border: 1px solid var(--border, #e5e7eb); border-radius: 12px; padding: 16px; text-align: center; }
        .value-card .vi { font-size: 1.6rem; margin-bottom: 6px; }
        .value-card h4  { font-size: .88rem; font-weight: 800; margin: 0 0 4px; }
        .value-card p   { font-size: .78rem; color: var(--muted, #6b7280); margin: 0; }
    </style>
</head>
<body <?php echo $user ? 'class="has-bottom-nav"' : ''; ?>>
<header class="app-topbar">
    <a href="<?php echo $user ? 'community.php' : 'index.php'; ?>" class="button button-secondary button-small">← Back</a>
    <span class="brand"><?php echo sanitize(APP_NAME); ?></span>
    <?php if (!$user): ?>
    <?php endif; ?>
</header>

<div class="about-shell">

    <div class="about-hero">
        <h1><?php echo sanitize(APP_NAME); ?></h1>
        <p>A community platform connecting skilled workers, customers, buyers, sellers, hosts, and neighbours across the Akuapem area — find skilled workers, buy and sell products, find a place to stay, request deliveries, share news, honour the departed, celebrate community events, and support each other.</p>
    </div>

    <!-- Mission -->
    <div class="about-section">
        <h2>Our Mission</h2>
        <p><?php echo sanitize(APP_NAME); ?> was built to bring the Akuapem community together in one digital space. We believe that local talent deserves a platform where they can be found and fairly paid, that goods can be bought and sold safely within the community, that a room, house, or guest house can be found without leaving home, and that items can be delivered quickly and reliably by local riders.</p>
        <p>We also serve as a digital notice board — making it easy to share community events, local news, funeral announcements, and sponsored advertisements with family and friends across Ghana and beyond.</p>
    </div>

    <!-- Services -->
    <div class="about-section">
        <h2>What We Offer</h2>
        <div class="service-grid">
            <div class="service-card">
                <div class="icon">💼</div>
                <h3>Jobs &amp; Services</h3>
                <p>Post service requests and hire skilled workers. Browse open jobs, apply, and get paid — all on a single platform.</p>
            </div>
            <div class="service-card">
                <div class="icon">🔧</div>
                <h3>Find Workers</h3>
                <p>Search for verified, skilled professionals by trade, location, and availability. View ratings, reviews, and profiles before you hire.</p>
            </div>
            <div class="service-card">
                <div class="icon">📅</div>
                <h3>Community Events</h3>
                <p>Discover and promote local events — festivals, church programmes, seminars, sports events, and more.</p>
            </div>
            <div class="service-card">
                <div class="icon">🕊️</div>
                <h3>Funeral Announcements</h3>
                <p>Share burial, wake keeping, and thanksgiving details with family and friends. A respectful way to reach the community quickly.</p>
            </div>
            <div class="service-card">
                <div class="icon">📰</div>
                <h3>News &amp; Articles</h3>
                <p>Submit and read local news, updates, and community stories. Admin-reviewed before publication for quality and accuracy.</p>
            </div>
            <div class="service-card">
                <div class="icon">💬</div>
                <h3>In-Platform Messaging</h3>
                <p>Communicate directly with workers or customers through our secure in-app chat. No need to share phone numbers upfront.</p>
            </div>
            <div class="service-card">
                <div class="icon">🏆</div>
                <h3>Worker Leaderboard</h3>
                <p>Top-rated and most active workers are recognised on our public leaderboard, celebrating excellence in the community.</p>
            </div>
            <div class="service-card">
                <div class="icon">🔒</div>
                <h3>Escrow Payments</h3>
                <p>Pay securely via escrow — funds are held safely and only released once the job is complete to your satisfaction.</p>
            </div>
            <div class="service-card">
                <div class="icon">✅</div>
                <h3>Worker Verification</h3>
                <p>Workers can submit identity documents for admin verification, earning a Verified badge that builds trust with customers.</p>
            </div>
            <div class="service-card">
                <div class="icon">⚖️</div>
                <h3>Dispute Resolution</h3>
                <p>If something goes wrong, our admins mediate disputes fairly and issue binding decisions on escrow fund release.</p>
            </div>
            <div class="service-card">
                <div class="icon">🎁</div>
                <h3>Referral &amp; Rewards</h3>
                <p>Earn points for inviting friends, completing jobs, and being an active community member. Points can unlock platform benefits.</p>
            </div>
            <div class="service-card">
                <div class="icon">⭐</div>
                <h3>Ratings &amp; Reviews</h3>
                <p>Customers rate workers after every completed job. Ratings build trust and help the best workers stand out.</p>
            </div>
            <div class="service-card">
                <div class="icon">🚚</div>
                <h3>Delivery Services</h3>
                <p>Request door-to-door delivery of parcels, documents, food, and more. Verified local riders pick up and deliver — track every step from request to drop-off.</p>
            </div>
            <div class="service-card">
                <div class="icon">🛵</div>
                <h3>Become a Delivery Rider</h3>
                <p>Register your bike, car, or van as a delivery agent. Accept jobs, set your availability, build your rating, and earn from every delivery you complete.</p>
            </div>
            <div class="service-card">
                <div class="icon">🛍️</div>
                <h3>Marketplace</h3>
                <p>Buy and sell products directly within the community. Browse 28+ product categories, add to cart, and check out securely. Sellers can open a free shop in minutes.</p>
            </div>
            <div class="service-card">
                <div class="icon">🏪</div>
                <h3>Seller Shops</h3>
                <p>Create your own branded shop page, list unlimited products with photos and descriptions, manage incoming orders, and grow your business with featured and sponsored listings.</p>
            </div>
            <div class="service-card">
                <div class="icon">📣</div>
                <h3>Advertisements</h3>
                <p>Promote your business with banner and sponsored advertisements displayed on the homepage and throughout the platform. Admin-managed for quality and relevance.</p>
            </div>
            <div class="service-card">
                <div class="icon">🧾</div>
                <h3>Receipts &amp; Payments</h3>
                <p>Every payment — jobs, marketplace orders, delivery fees, and boost listings — generates a downloadable receipt. Supports MTN MoMo, Telecel Cash, AirtelTigo, and card payments.</p>
            </div>
            <div class="service-card">
                <div class="icon">🏠</div>
                <h3>Accommodation</h3>
                <p>Find rooms, houses, hotels, and guest houses across the Akuapem area. Filter by town, price, and facilities, then message the host directly to arrange a viewing or booking.</p>
            </div>
            <div class="service-card">
                <div class="icon">🗓️</div>
                <h3>Nearby Markets</h3>
                <p>Order ahead from scheduled periodic markets like Ofie Market and Nkurakan Market, and collect from the storehouse on market day.</p>
            </div>
            <div class="service-card">
                <div class="icon">⚡</div>
                <h3>Quick Services</h3>
                <p>Airtime top-up, ECG and utility payments, exam results checkers, passport and Ghana Card assistance, and more — handled directly by our team.</p>
            </div>
            <div class="service-card">
                <div class="icon">🎉</div>
                <h3>Special Offers</h3>
                <p>Claim time-limited discounts, free access promotions, or redeem a promo code — check what's available and see your active offers anytime.</p>
            </div>
            <div class="service-card">
                <div class="icon">🔑</div>
                <h3>Sign in with Google</h3>
                <p>Register or log in instantly with your Google account — no separate password to remember.</p>
            </div>
        </div>
    </div>

    <!-- How it works -->
    <div class="about-section">
        <h2>How It Works</h2>
        <h3 style="font-size:.9rem;font-weight:700;margin:0 0 10px;color:#6b7280;">For Customers</h3>
        <div class="how-steps">
            <div class="step-card"><div class="num">Step 1</div><h4>Create Account</h4><p>Register for free in under a minute. Verify your email to unlock all features.</p></div>
            <div class="step-card"><div class="num">Step 2</div><h4>Post a Job</h4><p>Describe what you need, set a budget, and choose a location. Your listing goes live after admin approval.</p></div>
            <div class="step-card"><div class="num">Step 3</div><h4>Review Applications</h4><p>Workers apply and you (or a manager) review them. Approve the best fit.</p></div>
            <div class="step-card"><div class="num">Step 4</div><h4>Pay &amp; Complete</h4><p>Use escrow for peace of mind. Release payment once the job is done to your satisfaction.</p></div>
        </div>
        <h3 style="font-size:.9rem;font-weight:700;margin:18px 0 10px;color:#6b7280;">For Workers</h3>
        <div class="how-steps">
            <div class="step-card"><div class="num">Step 1</div><h4>Register as Worker</h4><p>Sign up, upload your ID, and select the skills and trades you offer.</p></div>
            <div class="step-card"><div class="num">Step 2</div><h4>Browse &amp; Apply</h4><p>See jobs matched to your skills. Apply with one tap. Set your availability so the right jobs find you.</p></div>
            <div class="step-card"><div class="num">Step 3</div><h4>Get Hired</h4><p>Once approved, the job is assigned to you. Chat with the customer and get to work.</p></div>
            <div class="step-card"><div class="num">Step 4</div><h4>Complete &amp; Earn</h4><p>Mark the job complete, receive your payment, and build your rating for future jobs.</p></div>
        </div>

        <h3 style="font-size:.9rem;font-weight:700;margin:24px 0 10px;color:#6b7280;">🚚 Requesting a Delivery</h3>
        <div class="how-steps">
            <div class="step-card"><div class="num">Step 1</div><h4>Create a Request</h4><p>Enter pickup and drop-off locations, describe the item, set your preferred date and payment method.</p></div>
            <div class="step-card"><div class="num">Step 2</div><h4>Admin Reviews</h4><p>Your request is reviewed to prevent fraud. Trusted customers with a good record are auto-approved instantly.</p></div>
            <div class="step-card"><div class="num">Step 3</div><h4>Riders Apply</h4><p>Approved riders in the area apply and can offer a custom delivery fee. You select the best rider.</p></div>
            <div class="step-card"><div class="num">Step 4</div><h4>Track &amp; Receive</h4><p>Follow live status updates — picked up, in transit, delivered. Rate your rider after delivery.</p></div>
        </div>

        <h3 style="font-size:.9rem;font-weight:700;margin:24px 0 10px;color:#6b7280;">🛍️ Buying on the Marketplace</h3>
        <div class="how-steps">
            <div class="step-card"><div class="num">Step 1</div><h4>Browse Products</h4><p>Search or filter by category, price range, condition, or seller location across 28+ product categories.</p></div>
            <div class="step-card"><div class="num">Step 2</div><h4>Add to Cart</h4><p>Add items from one or more shops to your cart. Review your items and total before proceeding.</p></div>
            <div class="step-card"><div class="num">Step 3</div><h4>Checkout</h4><p>Enter your delivery address (optionally with a Google Maps link), choose a payment method, and place your order.</p></div>
            <div class="step-card"><div class="num">Step 4</div><h4>Receive &amp; Review</h4><p>The seller confirms, packs, and ships your order. Download your receipt and rate the seller once delivered.</p></div>
        </div>

        <h3 style="font-size:.9rem;font-weight:700;margin:24px 0 10px;color:#6b7280;">🏪 Selling on the Marketplace</h3>
        <div class="how-steps">
            <div class="step-card"><div class="num">Step 1</div><h4>Open Your Shop</h4><p>Create your free shop page — add a name, description, logo, and contact details.</p></div>
            <div class="step-card"><div class="num">Step 2</div><h4>List Products</h4><p>Add products with photos, price, stock quantity, and condition. Submit for admin approval.</p></div>
            <div class="step-card"><div class="num">Step 3</div><h4>Receive Orders</h4><p>Customers place orders. Confirm, process, and mark as ready. The platform notifies riders automatically.</p></div>
            <div class="step-card"><div class="num">Step 4</div><h4>Boost &amp; Grow</h4><p>Feature or sponsor your products and shop for top visibility. Track your sales and revenue from the seller dashboard.</p></div>
        </div>

        <h3 style="font-size:.9rem;font-weight:700;margin:24px 0 10px;color:#6b7280;">🏠 Finding a Place to Stay</h3>
        <div class="how-steps">
            <div class="step-card"><div class="num">Step 1</div><h4>Browse Listings</h4><p>Choose Rooms &amp; Houses or Hotels &amp; Guest Houses, then filter by town, price, guests, and facilities.</p></div>
            <div class="step-card"><div class="num">Step 2</div><h4>Open a Listing</h4><p>View photos, pricing, and facilities, and see the host's contact details.</p></div>
            <div class="step-card"><div class="num">Step 3</div><h4>Message the Host</h4><p>Tap Contact Owner, Request Viewing, or Send Booking Enquiry to start a conversation directly with the host.</p></div>
            <div class="step-card"><div class="num">Step 4</div><h4>Arrange &amp; Pay Directly</h4><p>Agree on viewing, booking, and payment terms directly with the host — the platform connects you, but doesn't process accommodation bookings or payments.</p></div>
        </div>

        <h3 style="font-size:.9rem;font-weight:700;margin:24px 0 10px;color:#6b7280;">🔑 Listing Your Property</h3>
        <div class="how-steps">
            <div class="step-card"><div class="num">Step 1</div><h4>Add Your Listing</h4><p>Choose a category, add your location, price, facilities, and photos.</p></div>
            <div class="step-card"><div class="num">Step 2</div><h4>Admin Review</h4><p>Your listing is reviewed before it goes live to keep the directory trustworthy.</p></div>
            <div class="step-card"><div class="num">Step 3</div><h4>Respond to Enquiries</h4><p>Interested renters and guests message you directly through the platform.</p></div>
            <div class="step-card"><div class="num">Step 4</div><h4>Feature &amp; Grow</h4><p>Feature your listing for extra visibility and manage everything from My Listings.</p></div>
        </div>
    </div>

    <!-- Values -->
    <div class="about-section">
        <h2>Our Values</h2>
        <div class="values-row">
            <div class="value-card"><div class="vi">🤝</div><h4>Community First</h4><p>Built for and by the Akuapem community.</p></div>
            <div class="value-card"><div class="vi">🛡️</div><h4>Trust &amp; Safety</h4><p>Verified workers, riders, and sellers. Secure escrow, admin approval, and dispute resolution.</p></div>
            <div class="value-card"><div class="vi">⚡</div><h4>Speed</h4><p>Fast job matching, quick deliveries, and instant marketplace orders.</p></div>
            <div class="value-card"><div class="vi">🌍</div><h4>Local Impact</h4><p>Every hire, order, and delivery keeps money and opportunity within the community.</p></div>
            <div class="value-card"><div class="vi">📱</div><h4>Mobile First</h4><p>Designed from the ground up for smartphones — fast, simple, and works on any Android or iOS device.</p></div>
            <div class="value-card"><div class="vi">🔓</div><h4>Open Access</h4><p>Browse jobs, products, events, and news without an account. Register only when you're ready to participate.</p></div>
        </div>
    </div>

    <!-- CTA -->
    <div style="background:linear-gradient(135deg,#0f766e,#065f46);border-radius:16px;padding:28px 24px;text-align:center;color:#fff;">
        <h2 style="margin:0 0 8px;font-size:1.1rem;color:#fff;border:none;padding:0;">Ready to get started?</h2>
        <p style="color:#a7f3d0;font-size:.9rem;margin:0 0 16px;">Join <?php echo sanitize(APP_NAME); ?> today — it's free to register.</p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
            <?php if ($user): ?>
            <a href="jobs.php"         class="button button-secondary" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff;">Browse Jobs</a>
            <a href="marketplace.php"  class="button button-secondary" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff;">Marketplace</a>
            <a href="accommodation.php" class="button button-secondary" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff;">Accommodation</a>
            <a href="delivery.php"     class="button button-secondary" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff;">Deliveries</a>
            <a href="request.php"      class="button button-primary">Post a Job</a>
            <?php else: ?>
            <a href="register.php"     class="button button-primary">Create Free Account</a>
            <a href="marketplace.php"  class="button button-secondary" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff;">Browse Products</a>
            <a href="accommodation.php" class="button button-secondary" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff;">Accommodation</a>
            <a href="browse_jobs.php"  class="button button-secondary" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff;">Browse Jobs</a>
            <?php endif; ?>
        </div>
    </div>

    <p class="meta" style="margin-top:28px;padding-top:16px;border-top:1px solid var(--border,#e5e7eb);">
        <a href="contact.php">Contact us</a> &nbsp;·&nbsp;
        <a href="terms.php">Terms of Service</a> &nbsp;·&nbsp;
        <a href="privacy.php">Privacy Policy</a> &nbsp;·&nbsp;
        <a href="support.php">Help &amp; Support</a>
    </p>
</div>

<?php if ($user): $activeNav = 'settings'; require __DIR__ . '/partials/bottom_nav.php'; endif; ?>
</body>
</html>

<?php
// ============================================
// ROOT INDEX - Landing Page
// index.php (root directory)
// ============================================

// Check if user is already logged in
session_start();

if (isset($_SESSION['user_id'])) {
    // User is logged in, redirect to appropriate dashboard
    require_once 'includes/functions.php';
    
    if (isAdmin()) {
        header("Location: admin/index.php");
    } else {
        header("Location: user/index.php");
    }
    exit();
}

// User not logged in, show landing page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complaint Management System - Welcome</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            overflow-x: hidden;
        }
        
        /* Hero Section */
        .hero-section {
            min-height: 100vh;
            background: linear-gradient(135deg, #4a4d52 0%, #6b6e73 100%);
            position: relative;
            overflow: hidden;
        }
        
        .hero-section::before {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            top: -250px;
            right: -250px;
            animation: float 6s ease-in-out infinite;
        }
        
        .hero-section::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            bottom: -200px;
            left: -200px;
            animation: float 8s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0px) rotate(0deg);
            }
            50% {
                transform: translateY(-20px) rotate(5deg);
            }
        }
        
        .hero-content {
            position: relative;
            z-index: 10;
            padding: 100px 0;
        }
        
        .hero-title {
            font-size: 3.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 1.5rem;
            animation: fadeInUp 1s ease;
        }
        
        .hero-subtitle {
            font-size: 1.3rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2rem;
            animation: fadeInUp 1.2s ease;
        }
        
        .hero-buttons {
            animation: fadeInUp 1.4s ease;
        }
        
        .btn-hero {
            padding: 15px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            margin: 0 10px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-hero-primary {
            background: white;
            color: #667eea;
            border: 2px solid white;
        }
        
        .btn-hero-primary:hover {
            background: transparent;
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .btn-hero-secondary {
            background: transparent;
            color: white;
            border: 2px solid white;
        }
        
        .btn-hero-secondary:hover {
            background: white;
            color: #667eea;
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .hero-image {
            position: relative;
            z-index: 10;
            animation: fadeInRight 1.5s ease;
        }
        
        @keyframes fadeInRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        /* Features Section */
        .features-section {
            padding: 100px 0;
            background: #f8f9fa;
        }
        
        .section-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .section-subtitle {
            font-size: 1.1rem;
            color: #666;
            text-align: center;
            margin-bottom: 4rem;
        }
        
        .feature-card {
            background: white;
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            height: 100%;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            border-color: #667eea;
        }
        
        .feature-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 2rem;
        }
        
        .feature-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
        }
        
        .feature-description {
            color: #666;
            line-height: 1.6;
        }
        
        /* Stats Section */
        .stats-section {
            padding: 80px 0;
           background: linear-gradient(135deg, #4a4d52 0%, #6b6e73 100%);
            color: white;
        }
        
        .stat-card {
            text-align: center;
            padding: 30px;
        }
        
        .stat-number {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        /* How It Works Section */
        .how-it-works-section {
            padding: 100px 0;
            background: white;
        }
        
        .step-card {
            text-align: center;
            padding: 30px;
        }
        
        .step-number {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .step-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
        }
        
        .step-description {
            color: #666;
            line-height: 1.6;
        }
        
        /* CTA Section */
        .cta-section {
            padding: 100px 0;
            background: linear-gradient(135deg, #4a4d52 0%, #6b6e73 100%);
            text-align: center;
            color: white;
        }
        
        .cta-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .cta-subtitle {
            font-size: 1.2rem;
            margin-bottom: 40px;
            opacity: 0.9;
        }
        
        /* Footer */
        .footer {
            background: #2d3748;
            color: white;
            padding: 40px 0;
            text-align: center;
        }
        
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
        
        .footer a:hover {
            color: #764ba2;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }
            
            .hero-subtitle {
                font-size: 1.1rem;
            }
            
            .btn-hero {
                padding: 12px 30px;
                font-size: 1rem;
                margin: 10px 5px;
            }
            
            .section-title {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center hero-content">
                <div class="col-lg-6">
                    <h1 class="hero-title">Complaint Management System</h1>
                    <p class="hero-subtitle">
                        Streamline your complaint handling process with our powerful, easy-to-use system. 
                        Submit, track, and resolve complaints efficiently.
                    </p>
                    <div class="hero-buttons">
                        <a href="auth/login.php" class="btn-hero btn-hero-primary">
                            <i class="bi bi-box-arrow-in-right"></i> Login
                        </a>
                        <a href="auth/register.php" class="btn-hero btn-hero-secondary">
                            <i class="bi bi-person-plus"></i> Sign Up
                        </a>
                    </div>
                </div>
                <div class="col-lg-6 hero-image">
                    <div style="text-align: center; padding: 50px;">
                        <i class="bi bi-clipboard-check" style="font-size: 15rem; color: rgba(255, 255, 255, 0.9);"></i>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section">
        <div class="container">
            <h2 class="section-title">Why Choose Our System?</h2>
            <p class="section-subtitle">Powerful features designed to make complaint management effortless</p>
            
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-lightning-charge-fill"></i>
                        </div>
                        <h3 class="feature-title">Fast & Efficient</h3>
                        <p class="feature-description">
                            Submit complaints in seconds. Our streamlined process ensures quick resolution times.
                        </p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <h3 class="feature-title">Secure & Reliable</h3>
                        <p class="feature-description">
                            Your data is protected with enterprise-grade security. Track every complaint safely.
                        </p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-bell-fill"></i>
                        </div>
                        <h3 class="feature-title">Real-time Updates</h3>
                        <p class="feature-description">
                            Get instant notifications about your complaint status. Stay informed every step of the way.
                        </p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <h3 class="feature-title">Admin Dashboard</h3>
                        <p class="feature-description">
                            Powerful admin tools for managing, assigning, and resolving complaints efficiently.
                        </p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <h3 class="feature-title">Analytics & Reports</h3>
                        <p class="feature-description">
                            Track performance metrics, resolution times, and satisfaction ratings with detailed reports.
                        </p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-star-fill"></i>
                        </div>
                        <h3 class="feature-title">Rating System</h3>
                        <p class="feature-description">
                            Rate resolved complaints and provide feedback to help us improve our service.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats-section">
        <div class="container">
            <div class="row">
                <div class="col-md-3 col-6">
                    <div class="stat-card">
                        <div class="stat-number">1000+</div>
                        <div class="stat-label">Complaints Resolved</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-card">
                        <div class="stat-number">500+</div>
                        <div class="stat-label">Active Users</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-card">
                        <div class="stat-number">95%</div>
                        <div class="stat-label">Satisfaction Rate</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-card">
                        <div class="stat-number">24/7</div>
                        <div class="stat-label">Support Available</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="how-it-works-section">
        <div class="container">
            <h2 class="section-title">How It Works</h2>
            <p class="section-subtitle">Simple steps to get your complaints resolved</p>
            
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="step-card">
                        <div class="step-number">1</div>
                        <h3 class="step-title">Create Account</h3>
                        <p class="step-description">
                            Sign up in seconds with just your email and basic information.
                        </p>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="step-card">
                        <div class="step-number">2</div>
                        <h3 class="step-title">Submit Complaint</h3>
                        <p class="step-description">
                            Fill out a simple form describing your issue with attachments if needed.
                        </p>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="step-card">
                        <div class="step-number">3</div>
                        <h3 class="step-title">Track Progress</h3>
                        <p class="step-description">
                            Monitor your complaint status in real-time through your dashboard.
                        </p>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="step-card">
                        <div class="step-number">4</div>
                        <h3 class="step-title">Get Resolution</h3>
                        <p class="step-description">
                            Receive updates and rate your experience once resolved.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container">
            <h2 class="cta-title">Ready to Get Started?</h2>
            <p class="cta-subtitle">Join hundreds of users who trust our complaint management system</p>
            <div>
                <a href="auth/register.php" class="btn-hero btn-hero-primary">
                    <i class="bi bi-rocket-takeoff"></i> Get Started Now
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2026 Complaint Management System. All rights reserved.</p>
            <p class="mt-2">
                <a href="auth/login.php">Login</a> | 
                <a href="auth/register.php">Register</a> | 
                <a href="#">Privacy Policy</a> | 
                <a href="#">Terms of Service</a>
            </p>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
</body>
</html>
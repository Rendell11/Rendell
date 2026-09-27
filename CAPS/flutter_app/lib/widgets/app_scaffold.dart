import 'package:flutter/material.dart';

/// Small shared scaffold so every screen has the same app bar treatment.
class AppScaffold extends StatelessWidget {
  const AppScaffold({super.key, required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: SafeArea(child: child),
    );
  }
}
